<?php

declare(strict_types=1);

namespace Tempest\Http;

use Closure;
use Psr\Http\Message\ServerRequestInterface as PsrRequest;
use ReflectionException;
use Tempest\Container\Container;
use Tempest\Core\AppConfig;
use Tempest\Http\Exceptions\ControllerActionHasNoReturn;
use Tempest\Http\Exceptions\InvalidRouteException;
use Tempest\Http\Exceptions\MissingControllerOutputException;
use Tempest\Http\Mappers\RequestToPsrRequestMapper;
use Tempest\Http\Responses\Invalid;
use Tempest\Http\Responses\NotFound;
use Tempest\Http\Responses\Ok;
use function Tempest\map;
use Tempest\Reflection\ClassReflector;
use function Tempest\Support\str;
use Tempest\Validation\Exceptions\ValidationException;
use Tempest\View\View;

/**
 * @template MiddlewareClass of \Tempest\Http\HttpMiddleware
 */
final class GenericRouter implements Router
{
    public const string REGEX_MARK_TOKEN = 'MARK';

    /** @var class-string<MiddlewareClass>[] */
    private array $middleware = [];

    public function __construct(
        private readonly Container $container,
        private readonly RouteConfig $routeConfig,
        private readonly AppConfig $appConfig,
    ) {
    }

    public function dispatch(Request|PsrRequest $request): Response
    {
        if (! $request instanceof PsrRequest) {
            $request = map($request)->with(RequestToPsrRequestMapper::class);
        }

        $matchedRoute = $this->matchRoute($request);

        if ($matchedRoute === null) {
            return new NotFound();
        }

        $this->container->singleton(
            MatchedRoute::class,
            fn () => $matchedRoute,
        );

        $callable = $this->getCallable($matchedRoute);

        try {
            $request = $this->resolveRequest($request, $matchedRoute);
            $response = $callable($request);
        } catch (ValidationException $validationException) {
            // TODO: refactor to middleware
            return new Invalid($request, $validationException->failingRules);
        }

        if ($response === null) {
            throw new MissingControllerOutputException(
                $matchedRoute->route->handler->getDeclaringClass()->getName(),
                $matchedRoute->route->handler->getName(),
            );
        }

        return $response;
    }

    private function matchRoute(PsrRequest $request): ?MatchedRoute
    {
        // Try to match routes without any parameters
        if (($staticRoute = $this->matchStaticRoute($request)) !== null) {
            return $staticRoute;
        }

        // match dynamic routes
        return $this->matchDynamicRoute($request);
    }

    private function getCallable(MatchedRoute $matchedRoute): Closure
    {
        $route = $matchedRoute->route;

        $callControllerAction = function (Request $request) use ($route, $matchedRoute) {
            $response = $this->container->invoke(
                $route->handler,
                ...$matchedRoute->params,
            );

            if ($response === null) {
                throw new ControllerActionHasNoReturn($route);
            }

            return $response;
        };

        $callable = fn (Request $request) => $this->createResponse($callControllerAction($request));

        $middlewareStack = [...$this->middleware, ...$route->middleware];

        while ($middlewareClass = array_pop($middlewareStack)) {
            $callable = function (Request $request) use ($middlewareClass, $callable) {
                /** @var HttpMiddleware $middleware */
                $middleware = $this->container->get($middlewareClass);

                return $middleware($request, $callable);
            };
        }

        return $callable;
    }

    public function toUri(array|string $action, ...$params): string
    {
        try {
            if (is_array($action)) {
                $controllerClass = $action[0];
                $reflection = new ClassReflector($controllerClass);
                $controllerMethod = $reflection->getMethod($action[1]);
            } else {
                $controllerClass = $action;
                $reflection = new ClassReflector($controllerClass);
                $controllerMethod = $reflection->getMethod('__invoke');
            }

            $routeAttribute = $controllerMethod->getAttribute(Route::class);

            $uri = $routeAttribute->uri;
        } catch (ReflectionException) {
            if (is_array($action)) {
                throw new InvalidRouteException($action[0], $action[1]);
            }

            $uri = $action;
        }

        $queryParams = [];


        foreach ($params as $key => $value) {
            if (! str_contains($uri, "{$key}")) {
                $queryParams[$key] = $value;

                continue;
            }

            $pattern = '#\{' . $key . Route::ROUTE_PARAM_CUSTOM_REGEX . '\}#';
            $uri = preg_replace($pattern, (string)$value, $uri);
        }

        $uri = rtrim($this->appConfig->baseUri, '/') . $uri;

        if ($queryParams !== []) {
            return $uri . '?' . http_build_query($queryParams);
        }

        return $uri;
    }

    private function resolveParams(Route $route, string $uri): ?array
    {
        if ($route->uri === $uri) {
            return [];
        }

        $tokens = str($route->uri)->matchAll('#\{'. Route::ROUTE_PARAM_NAME_REGEX . Route::ROUTE_PARAM_CUSTOM_REGEX .'\}#', );

        if (empty($tokens)) {
            return null;
        }

        $tokens = $tokens[1];

        $matches = str($uri)->matchAll("#^$route->matchingRegex$#");

        if (empty($matches)) {
            return null;
        }

        unset($matches[0]);

        $matches = array_values($matches);

        $valueMap = [];

        foreach ($matches as $i => $match) {
            $valueMap[trim($tokens[$i], '{}')] = $match[0];
        }

        return $valueMap;
    }

    private function createResponse(Response|View $input): Response
    {
        if ($input instanceof View) {
            return new Ok($input);
        }

        return $input;
    }

    private function matchStaticRoute(PsrRequest $request): ?MatchedRoute
    {
        $staticRoute = $this->routeConfig->staticRoutes[$request->getMethod()][$request->getUri()->getPath()] ?? null;

        if ($staticRoute === null) {
            return null;
        }

        return new MatchedRoute($staticRoute, []);
    }

    private function matchDynamicRoute(PsrRequest $request): ?MatchedRoute
    {
        // If there are no routes for the given request method, we immediately stop
        $routesForMethod = $this->routeConfig->dynamicRoutes[$request->getMethod()] ?? null;
        if ($routesForMethod === null) {
            return null;
        }

        // First we get the Routing-Regex for the request method
        $matchingRegexForMethod = $this->routeConfig->matchingRegexes[$request->getMethod()];

        // Then we'll use this regex to see whether we have a match or not
        $matchResult = preg_match($matchingRegexForMethod, $request->getUri()->getPath(), $matches);

        if (! $matchResult || ! array_key_exists(self::REGEX_MARK_TOKEN, $matches)) {
            return null;
        }

        $route = $routesForMethod[$matches[self::REGEX_MARK_TOKEN]];

        // TODO: we could probably optimize resolveParams now,
        //  because we already know for sure there's a match
        $routeParams = $this->resolveParams($route, $request->getUri()->getPath());

        // This check should _in theory_ not be needed,
        // since we're certain there's a match
        if ($routeParams === null) {
            return null;
        }

        return new MatchedRoute($route, $routeParams);
    }

    private function resolveRequest(PsrRequest $psrRequest, MatchedRoute $matchedRoute): Request
    {
        // Let's find out if our input request data matches what the route's action needs
        $requestClass = GenericRequest::class;

        // We'll loop over all the handler's parameters
        foreach ($matchedRoute->route->handler->getParameters() as $parameter) {

            // If the parameter's type is an instance of Request…
            if ($parameter->getType()->matches(Request::class)) {
                // We'll use that specific request class
                $requestClass = $parameter->getType()->getName();

                break;
            }
        }

        // We map the original request we got into this method to the right request class
        /** @var Request $request */
        $request = map($psrRequest)->to($requestClass);

        // Next, we register this newly created request object in the container
        // This makes it so that RequestInitializer is bypassed entirely when the controller action needs the request class
        // Making it so that we don't need to set any $_SERVER variables and stuff like that
        $this->container->singleton(Request::class, fn () => $request);
        $this->container->singleton($request::class, fn () => $request);

        // Finally, we validate the request
        $request->validate();

        return $request;
    }

    public function addMiddleware(string $middlewareClass): void
    {
        $this->middleware[] = $middlewareClass;
    }
}
