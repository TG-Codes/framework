<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\Http\Router;

use Predis\Response\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Components\Http\MiddlewareInterface;
use Spiral\Components\Http\Response\Redirect;
use Spiral\Core\Component;
use Spiral\Core\Container;

class Router extends Component implements MiddlewareInterface
{
    /**
     * Internal name for primary (default) route. Primary route used to resolve url and perform controller
     * based routing in cases where no other route found.
     *
     * Primary route should support <controller> and <action> parameters. Basically this is multi
     * controller route. Primary route should be instance of spiral DirectRoute or compatible.
     */
    const PRIMARY_ROUTE = 'primary';

    /**
     * Container.
     *
     * @invisible
     * @var Container
     */
    protected $container = null;

    /**
     * Registered routes.
     *
     * @var RouteInterface[]
     */
    protected $routes = [];

    /**
     * Set of route specific middlewares aliases by short string name. This technique allows developer
     * to assign middleware for group of routes.
     *
     * @var array|MiddlewareInterface[]|callable[]
     */
    protected $middlewareAliases = [];

    /**
     * Base path fetched automatically from request attribute "activePath" which is populated by
     * HttpDispatcher while selecting appropriate endpoint. Base path used to correctly resolve route
     * url and pattern when website or module associated with non empty URI path.
     *
     * @var string
     */
    protected $activePath = '/';

    /**
     * Active route instance, this value will be populated only after router successfully handled
     * incoming request.
     *
     * @var RouteInterface|null
     */
    protected $activeRoute = null;

    /**
     * Router middleware used by HttpDispatcher and modules to perform URI based routing with defined
     * endpoint such as controller action, closure or middleware.
     *
     * @param Container   $container
     * @param Route|array $routes             Pre-defined array of routes (if were collected externally).
     * @param array       $primaryRoute       Default route options (controller route), should include
     *                                        pattern and target.
     */
    public function __construct(
        Container $container,
        array $routes = [],
        array $primaryRoute = []
    )
    {
        $this->container = $container;

        foreach ($routes as $route)
        {
            if (!$route instanceof RouteInterface)
            {
                throw new \InvalidArgumentException("Routes should be array of Route instances.");
            }

            //Name aliasing is required to perform URL generation later.
            $this->routes[$route->getName()] = $route;
        }

        //Registering default route which should handle all unhandled controllers
        if (!isset($this->routes[self::PRIMARY_ROUTE]) && !empty($primaryRoute))
        {
            $this->routes[self::PRIMARY_ROUTE] = new DirectRoute(
                self::PRIMARY_ROUTE,
                $primaryRoute['pattern'],
                $primaryRoute['namespace'],
                $primaryRoute['postfix'],
                $primaryRoute['defaults'],
                $primaryRoute['controllers']
            );
        }
    }

    /**
     * Create new middleware alias which can be used in any route by it's short name.
     *
     * @param string                       $alias
     * @param callable|MiddlewareInterface $middleware
     */
    public function registerMiddleware($alias, $middleware)
    {
        $this->middlewareAliases[$alias] = $middleware;
    }

    /**
     * Handle request generate response. Middleware used to alter incoming Request and/or Response
     * generated by inner pipeline layers.
     *
     * @param ServerRequestInterface $request Server request instance.
     * @param \Closure               $next    Next middleware/target.
     * @param object|null            $context Pipeline context, can be HttpDispatcher, Route or module.
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, \Closure $next = null, $context = null)
    {
        //Open router scope
        $outerRouter = $this->container->getBinding('router');
        $this->container->bind('router', $this);

        $this->activePath = $request->getAttribute('activePath', $this->activePath);
        if (!$this->activeRoute = $this->findRoute($request, $this->activePath))
        {
            throw new RouterException("No routes matched given request.");
        }

        //Executing found route
        $response = $this->activeRoute->perform(
            $request->withAttribute('route', $this->activeRoute),
            $this->container,
            $this->middlewareAliases
        );

        //Close router scope
        $this->container->removeBinding('router');
        !empty($outerRouter) && $this->container->bind('router', $outerRouter);

        return $response;
    }

    /**
     * Find route matched for given request.
     *
     * @param ServerRequestInterface $request
     * @param string                 $basePath
     * @return null|RouteInterface
     */
    protected function findRoute(ServerRequestInterface $request, $basePath)
    {
        foreach ($this->routes as $route)
        {
            if ($route->match($request, $basePath))
            {
                return $route;
            }
        }

        return null;
    }

    /**
     * Add new Route instance to router stack, route has to be added before router handled request.
     *
     * @param RouteInterface $route
     */
    public function addRoute(RouteInterface $route)
    {
        $this->routes[] = $route;
    }

    /**
     * All registered routes.
     *
     * @return RouteInterface[]
     */
    public function getRoutes()
    {
        return $this->routes;
    }

    /**
     * Get route by name. Use Router::PRIMARY_ROUTE to get default route.
     *
     * @param string $route
     * @return RouteInterface
     * @throws RouterException
     */
    public function getRoute($route)
    {
        if (!isset($this->routes[$route]))
        {
            throw new RouterException("Undefined route '{$route}'.");
        }

        return $this->routes[$route];
    }

    /**
     * Get currently active route, this value will be populated only after router successfully handled
     * incoming request.
     *
     * @return RouteInterface|null
     */
    public function activeRoute()
    {
        return $this->activeRoute;
    }

    /**
     * Generate url using route name and set of provided parameters. Parameters will be automatically
     * injected to route pattern and prefixed with activePath value.
     *
     * You can enter controller::action type route, in this case appropriate controller and action
     * will be injected into default route as controller and action parameters accordingly. Default
     * route should be instance of spiral DirectRoute or compatible.
     *
     * Example:
     * $this->router->url('post::view', ['id' => 1]);
     * $this->router->url('post/view', ['id' => 1]);
     *
     * @param string $route      Route name.
     * @param array  $parameters Route parameters including controller name, action and etc.
     * @return string
     * @throws RouterException
     */
    public function url($route, array $parameters = [])
    {
        if (!isset($this->routes[$route]))
        {
            //Will be handled via default route where route name is specified as controller::action
            if (strpos($route, Route::CONTROLLER_SEPARATOR) == false && strpos($route, '/') === false)
            {
                throw new RouterException(
                    "Unable to locate route or use default route with controller/action pattern."
                );
            }

            list($controller, $action) = explode(
                Route::CONTROLLER_SEPARATOR,
                str_replace('/', Route::CONTROLLER_SEPARATOR, $route)
            );

            $parameters = compact('controller', 'action') + $parameters;
            $route = self::PRIMARY_ROUTE;
        }

        return $this->routes[$route]->createURL($parameters, $this->activePath);
    }

    /**
     * Generate redirect based on url rendered using specified route pattern.
     *
     * You can enter controller::action type route, in this case appropriate controller and action
     * will be injected into default route as controller and action parameters accordingly. Default
     * route should be instance of spiral DirectRoute or compatible.
     *
     * Example:
     * return $this->router->redirect('post::view', ['id' => 1]);
     *
     * @param string $route      Route name.
     * @param array  $parameters Route parameters including controller name, action and etc.
     * @return Redirect
     */
    public function redirect($route, array $parameters = [])
    {
        return new Redirect($this->url($route, $parameters));
    }
}