<?php namespace GeneaLabs\LaravelMixpanel\Listeners;

use GeneaLabs\LaravelMixpanel\LaravelMixpanel;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class LaravelMixpanelEventHandler
{
    protected $guard;
    protected $mixPanel;
    protected $request;

    /**
     * @param Request         $request
     * @param Guard           $guard
     * @param LaravelMixpanel $mixPanel
     */
    public function __construct(Request $request, Guard $guard, LaravelMixpanel $mixPanel)
    {
        $this->guard = $guard;
        $this->mixPanel = $mixPanel;
        $this->request = $request;
    }

    /**
     * @param array $event
     */
    public function onUserLoginAttempt(Attempting $event)
    {
        $email = $event->credentials['email'] ?: '';
        $password = $event->credentials['password'] ?: '';

        $user = App::make(config('auth.model'))->where('email', $email)->first();

        if ($user
            && ! $this->guard->getProvider()->validateCredentials($user, ['email' => $email, 'password' => $password])
        ) {
            $this->mixPanel->identify($user->getKey());
            $this->mixPanel->track('Session', ['Status' => 'Login Failed']);
        }
    }

    /**
     * @param Model $user
     */
    public function onUserLogin(Login $login)
    {
        $user = $login->user;
        $firstName = $user->first_name;
        $lastName = $user->last_name;

        if ($user->name) {
            $nameParts = explode(' ', $user->name);
            array_filter($nameParts);
            $lastName = array_pop($nameParts);
            $firstName = implode(' ', $nameParts);
        }

        $data = [
            '$first_name' => $firstName,
            '$last_name' => $lastName,
            '$name' => $user->name,
            '$email' => $user->email,
            '$created' => ($user->created_at
                ? $user->created_at->format('Y-m-d\Th:i:s')
                : null),
        ];
        array_filter($data);
        $this->mixPanel->identify($user->getKey());
        $this->mixPanel->people->set($user->getKey(), $data, $this->request->ip());
        $this->mixPanel->track('Session', ['Status' => 'Logged In']);
    }

    /**
     * @param Model $user
     */
    public function onUserLogout(Logout $logout)
    {
        $user = $logout->user;

        if ($user) {
            $this->mixPanel->identify($user->getKey());
        }

        $this->mixPanel->track('Session', ['Status' => 'Logged Out']);
    }

    /**
     * @param RouteMatched $route
     */
    public function onViewLoad(RouteMatched $routeMatched)
    {
        if (Auth::check()) {
            $this->mixPanel->identify(Auth::user()->getKey());
            $this->mixPanel->people->set(Auth::user()->getKey(), [], $this->request->ip());
        }

        $route = $routeMatched->route;
        $routeAction = $route->getAction();
        $route = (is_array($routeAction) && array_key_exists('as', $routeAction) ? $routeAction['as'] : null);
        $this->mixPanel->track('Page View', ['Route' => $route]);
    }

    /**
     * @param Dispatcher $events
     */
    public function subscribe(Dispatcher $events)
    {
        $events->listen('auth.attempt', self::class . '@onUserLoginAttempt');
        $events->listen('auth.login', self::class . '@onUserLogin');
        $events->listen('auth.logout', self::class . '@onUserLogout');
        $events->listen(Attempting::class, self::class . '@onUserLoginAttempt');
        $events->listen(Login::class, self::class . '@onUserLogin');
        $events->listen(Logout::class, self::class . '@onUserLogout');
        $events->listen('Illuminate\Routing\Events\RouteMatched', 'GeneaLabs\LaravelMixpanel\Listeners\LaravelMixpanelEventHandler@onViewLoad');
    }
}
