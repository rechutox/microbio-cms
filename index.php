<?php

/*
 *  CONTAINER:  http://pimple.sensiolabs.org/
 *  CONFIG:     https://github.com/hassankhan/config
 *  TEMPLATES:  http://twig.sensiolabs.org/doc
 *  FILESYSTEM: https://github.com/thephpleague/flysystem
 *  ROUTER:     https://github.com/mrjgreen/phroute
 *  MARKDOWN:   https://github.com/erusev/parsedown
 *  SESSION:    https://github.com/auraphp/Aura.Session
 *  AUTH:       https://github.com/auraphp/Aura.Auth
 */

define('BOOT_START', microtime(true));

require_once 'vendor/autoload.php';

use Pimple\Container;
use Noodlehaus\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;
use Phroute\Phroute\RouteCollector;
use Phroute\Phroute\Dispatcher;
use Aura\Session\SessionFactory;
use Aura\Auth\AuthFactory;

$app = new Container();

$app['config']       = function($app) { return new Config('config.yml'); };
$app['auth_factory'] = function($app) { return new AuthFactory($_COOKIE); };
$app['auth.adapter'] = function($app) { return new App\ConfigDefinedLoginAdapter(config('user_accounts')); };
$app['auth']         = function($app) { return $app['auth_factory']->newInstance(); };

$resume_service = $app['auth_factory']->newResumeService();
$resume_service->resume($app['auth']);

$app['session']      = function($app) { return (new SessionFactory)->newInstance($_COOKIE); };
$app['filesystem']   = function($app) { return new Filesystem(new Local(__DIR__.'/content', $app['config']['lock_filesystem_writes'])); };
$app['router']       = function($app) { return new RouteCollector(); };
$app['markdown']     = function($app) { return new Parsedown(); };
$app['twig']         = function($app) {
    return new Twig_Environment(
        new Twig_Loader_Filesystem(__DIR__.$app['config']['theme_folder'].'/'.$app['config']['theme'], [
            'debug'            => $app['config']['twig']['debug'],
            'cache'            => $app['config']['twig']['use_cache'] ? __DIR__.$app['config']['twig']['cache_path'] : false,
            'auto_reload'      => $app['config']['twig']['auto_reload'],
            'strict_variables' => $app['config']['twig']['strict_variables'],
            'autoescape'       => $app['config']['twig']['autoescape'],
    ]));
};
//$app['cache']
//$app['request']
//$app['session']
$app['BOOT_START'] = BOOT_START;


$app['session']->setCookieParams(['lifetime' => config('cookie_lifetime')]);


//------------
// TWIG THINGS
//------------


$app['twig']->addGlobal('config', $app['config']);

$app['twig']->addFunction(new Twig_SimpleFunction('app_render_time', function() use ($app) {
    return (microtime(true) - $app['BOOT_START']);
}));

$app['twig']->addFunction(new Twig_SimpleFunction('debug', function($var) use ($app) {
    return $app['twig']->render('debug.twig', ['body' => var_export($var, true)]);
}));

$app['twig']->addFunction(new Twig_SimpleFunction('asset', function($path) use ($app) {
    return 'http://' . $_SERVER['HTTP_HOST'] . $app['config']['theme_folder'] . '/' . $app['config']['theme'] . '/' . $path;
}));

$app['twig']->addFunction(new Twig_SimpleFunction('session_alerts', function() use ($app) {
    return getFlashAlerts();
}));

$app['twig']->addFunction(new Twig_SimpleFunction('auth', function() use ($app) {
    return auth();
}));

$app['twig']->addFunction(new Twig_SimpleFunction('session', function() use ($app) {
    return session();
}));

$app['twig']->addFunction(new Twig_SimpleFunction('csrf_field', function() use ($app) {
    $csrf_value = session()->getCsrfToken()->getValue();
    return sprintf("<input type='hidden' name='_csrf_token_' value='%s'>", htmlspecialchars($csrf_value, ENT_QUOTES, 'UTF-8'));
}));


//--------
// HELPERS
//--------

function view(string $template, $context = []) {
    global $app;
    return $app['twig']->render($template, $context);
}

function config(string $key, $default = null) {
    global $app;
    return $app['config']->get($key, $default);
}

function redirect(string $path, $code = 302) {
    $loc = sprintf('Location: %s', $path);
    header($loc, true, $code);
    exit;
}

function session($segment = null) {
    global $app;
    return $app['session'];
}

function flash(string $key, $obj, $now = false) {
    global $app;

    $seg = $app['session']->getSegment(config('app_session_segment'));

    if ($now)
        $seg->setFlashNow($key, $obj);
    else
        $seg->setFlash($key, $obj);
}

function getFlash(string $key, $default, $for_next_request = false) {
    global $app;

    $seg = $app['session']->getSegment(config('app_session_segment'));

    if ($for_next_request)
        return $seg->getFlashNext($key, $default);
    return $seg->getFlash($key, $default);
}

function getFlashAlerts($for_next_request = false) {
    return getFlash('flash_alerts', [], $for_next_request);
}

function flashAlert(string $msg, $type = 'info', $html = false, $now = false) {
    $alerts   = getFlashAlerts(true);
    $alerts[] = [
        'message' => $msg,
        'type'    => $type,
        'html'    => $html
    ];
    flash('flash_alerts', $alerts, $now);
}

function auth() {
    global $app;
    return $app['auth'];
}

function login($user, $pass) {
    global $app;
    try {
        $adapter = $app['auth.adapter'];
        $loginService = $app['auth_factory']->newLoginService($adapter);
        $logindata = $loginService->login($app['auth'], ['user'=>$user, 'password'=>$pass]);
    } catch (App\InvalidLoginException $e) {
        flashAlert($e->getMessage(), "error");
        return false;
    }
    return true;
}

function logout() {
    global $app;
    $adapter = $app['auth.adapter'];
    $logoutService = $app['auth_factory']->newLogoutService($adapter);
    $logoutService->logout($app['auth']);
    // Destruimos la session y regeneramos su id para evitar
    // que nos roben algo que pudo quedar en las cookies
    // evitando asi un posible hijacking
    // Pilas, esto elimina cualquier flash que hayas tirado ante de esto
    session()->destroy();
    session()->regenerateId();
}

function setPageStartTime($t) {
    global $app;
    $app['PAGE_START'] = $t;
}

function getPageStartTime() {
    global $app;
    return $app['PAGE_START'];
}

function getPageProcessTime() {
    (microtime(true) - getPageStartTime());
}


//--------
// ROUTES
//--------


// Route filter
$app['router']->filter('auth', function() {
    if (auth()->isExpired()) {
        flashAlert('Your session has expired, please login again.', 'warning');
        redirect('/login');
    }
    else if (auth()->isIdle()) {
        flashAlert('Your session is stale, please login again.', 'warning');
        redirect('/login');
    }
    else if (!auth()->isValid()) {
        flashAlert('You need to loged in to do that!', 'error');
        redirect('/login');
    }
    return null;
});

// Route filter
$app['router']->filter('guest_only', function() {
    if (!auth()->isAnon()) {
        redirect('/');
    }
    return null;
});

// Route filter
$app['router']->filter('csrf_check', function() {
    $unsafe = $_SERVER['REQUEST_METHOD'] == 'POST'
           || $_SERVER['REQUEST_METHOD'] == 'PUT'
           || $_SERVER['REQUEST_METHOD'] == 'DELETE';

    if ($unsafe && auth()->isValid()) {
        $csrf_value = isset($_POST['_csrf_token_']) ? $_POST['_csrf_token_'] : '';
        $csrf_token = session()->getCsrfToken();
        if (!$csrf_token->isValid($csrf_value)) {
            flashAlert("This smells like a cross-site request forgery... YOU PIG!", "error");
            redirect('/');
        } else {
            //"This looks like a valid request.";
            return null;
        }
    } else {
        //"CSRF attacks only affect unsafe requests by authenticated users.";
        return null;
    }

    return null;
});

// Route filter
$app['router']->filter('page_file_check', function() use ($app) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path[0] = '';
    $filesystem = $app['filesystem'];
    $file_name = $path.'.md';
    $exists = $filesystem->has($file_name);
    if (!$exists) {
        throw new Exception('Error Processing Request: 404');
        return false;
    }
    return null;
});

// Route filter
$app['router']->filter('statsStart', function() {
    //setPageStartTime(microtime(true));
});

// Route filter
$app['router']->filter('statsComplete', function() {
    //var_dump('Page load time: ' . (microtime(true) - getPageStartTime()));
});

$app['router']->group(['before' => 'statsStart', 'after' => 'statsComplete'], function($router) use ($app) {

    // index
    $app['router']->any(['/', 'home'], function() use ($app) {
        return view(config('theme_default_templates.home'));
    });

    // LOGIN
    $app['router']->get(['/login', 'login'], function() use ($app) {
        return view(config('theme_default_templates.login'));
    }, ['before' => 'guest_only']);
    $app['router']->post(['/login', 'login'], function() use ($app) {
        $user = $_REQUEST['user'];
        $pass = $_REQUEST['password'];
        if (login($user, $pass)) {
            flashAlert('You have successfully login!', 'success');
            redirect('/');
        } else {
            redirect('/login');
        }
    }, ['before' => 'csrf_check']);
    $app['router']->any(['/logout', 'logout'], function() use ($app) {
        logout();
        flashAlert('You had logout... Come back soon!', 'info');
        redirect('/');
    });


    // POST STORE PAGE
    $app['router']->post(['/microbio/create-page', 'store-page'], function() use ($app) {
        $title = $_REQUEST['title'];
        $slug  = $_REQUEST['slug'];
        $body  = $_REQUEST['body'];

        $slug = preg_replace("/[^a-z0-9-]/", "-", strtolower($slug));

        $file_name = $slug.'.md';
        try {
            $app['filesystem']->put($file_name, $body);
        } catch(Exception $e) {
            echo "PEO: ".$e->message;
            redirect('/');
        }
        flashAlert('The page has been created', 'success');
        redirect('/'.$slug);
    }, ['before' => ['auth', 'csrf']]);

    // POST UPDATE PAGE
    $app['router']->post(['/microbio/update-page', 'update-page'], function() use ($app) {
        $title     = $_REQUEST['title'];
        $slug      = $_REQUEST['slug'];
        $old_slug  = $_REQUEST['old_slug'];
        $body      = $_REQUEST['body'];

        $slug = preg_replace("/[^a-z0-9-]/", "-", strtolower($slug));

        $old_file_name = $old_slug.'.md';
        $file_name = $slug.'.md';

        try {
            if ($old_file_name == $file_name)
                $app['filesystem']->put($file_name, $body);
            else {
                $app['filesystem']->delete($old_file_name);
                $app['filesystem']->put($file_name, $body);
            }
        } catch(Exception $e) {
            echo "PEO: ".$e->message;
            redirect('/');
        }
        flashAlert('The page has been updated', 'success');
        redirect('/'.$slug);
    }, ['before' => ['auth', 'csrf_check']]);

    // ANY CREATE PAGE
    $app['router']->any(['/create-page', 'create-page'], function() use ($app) {
        return view(config('theme_default_templates.create_record'));
    }, ['before' => 'auth']);

    // ANY PAGES LISTING
    $app['router']->any(['/pages', 'all-pages'], function() use ($app) {
        $dir_cont = $app['filesystem']->listContents('/', true);
        $files = [];
        foreach ($dir_cont as $entry) {
            $tokens = explode('.', $entry['path']);
            $name = '';
            $ext = '';
            if (count($tokens) == 1)
                $ext = $tokens[0];
            else {
                $name = $tokens[0];
                $ext  = $tokens[1];
            }
            if ($entry['type'] == 'file' && $ext == 'md') {
                $file = [];
                $file['pagename'] = $name;
                $file['path'] = $entry['path'];
                $file['link'] = '/'.$file['pagename'];
                $files[] = $file;
            }
        }
        return view(config('theme_default_templates.records'), ['files'=>$files]);
    });

    // ANY SHOW PAGE
    $app['router']->any(['/{page:c}', 'page'], function($page) use ($app) {
        $file_name = $page.'.md';
        $contents = $app['filesystem']->read($file_name);
        $page = [
            'slug' => $page,
            'body' => $app['markdown']->text($contents),
        ];
        return view(config('theme_default_templates.record'), ['page'=>$page]);
    }, ['before' => 'page_file_check']);

    // ANY PAGE EDIT
    $app['router']->any(['/{page:c}/edit', 'edit-page'], function($page) use ($app) {
        $record = [
            'slug' => $page,
            'body' => $app['filesystem']->read($page.'.md'),
        ];
        return view(config('theme_default_templates.edit_record'), ['record'=>(object)$record]);
    }, ['before' => 'auth']);

    // ANY PAGE DELETE
    $app['router']->any(['/{page:c}/delete', 'delete-page'], function($page) use ($app) {
        $app['filesystem']->delete($page.'.md');
        flashAlert('The page has been deleted', 'success');
        redirect('/pages');
    }, ['before' => 'auth']);

});





// NB. You can cache the return value from $router->getData() so you don't have to create the routes each request - massive speed gains
$dispatcher = new Dispatcher($app['router']->getData());

$response = $dispatcher->dispatch($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Print out the value returned from the dispatched function
echo $response;