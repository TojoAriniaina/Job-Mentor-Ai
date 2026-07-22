<?php
namespace App;

class Router {
    private array $routes = [];

    public function get(string $path, string $controller, string $method): self {
        $this->routes['GET'][$path] = ['controller' => $controller, 'method' => $method];
        return $this;
    }

    public function post(string $path, string $controller, string $method): self {
        $this->routes['POST'][$path] = ['controller' => $controller, 'method' => $method];
        return $this;
    }

    public function delete(string $path, string $controller, string $method): self {
        $this->routes['DELETE'][$path] = ['controller' => $controller, 'method' => $method];
        return $this;
    }

    public function dispatch(string $httpMethod, string $uri): void {
        // Nettoyer l'URI : supprimer query string et trailing slash
        $uri = parse_url($uri, PHP_URL_PATH);
        $uri = rtrim($uri, '/');
        if (empty($uri)) $uri = '/';

        // Quand le projet n'est pas la racine du serveur (ex: htdocs/Job-Mentor-Ai/),
        // l'URI contient le nom du dossier projet en préfixe. On le retire.
        // Ex: /Job-Mentor-Ai/api/cv/generate → /api/cv/generate
        $apiPos = strpos($uri, '/api/');
        if ($apiPos !== false && $apiPos > 0) {
            $uri = substr($uri, $apiPos);
        } elseif ($uri !== '/' && $uri !== '' && strpos($uri, '/api') === false) {
            // Vérifier si on est sur une route legacy (?action=)
            $action = $_GET['action'] ?? null;
            if (!$action) {
                // Ni /api/* ni action → probablement la racine du projet
                $segments = explode('/', trim($uri, '/'));
                // Si le premier segment correspond à un dossier existant, le retirer
                if (!empty($segments[0]) && is_dir(__DIR__ . '/../' . $segments[0])) {
                    $uri = '/' . implode('/', array_slice($segments, 1));
                    if (empty($uri)) $uri = '/';
                }
            }
        }

        // Support de l'ancien format ?action= pour rétrocompatibilité
        $action = $_GET['action'] ?? null;
        if ($action) {
            // Mapper l'ancien format vers la nouvelle route
            $mapped = $this->mapLegacyAction($httpMethod, $uri, $action);
            if ($mapped) {
                $uri = $mapped['path'];
                $httpMethod = $mapped['method'] ?? $httpMethod;
            }
        }

        // Route exacte
        if (isset($this->routes[$httpMethod][$uri])) {
            $route = $this->routes[$httpMethod][$uri];
            $this->callAction($route['controller'], $route['method']);
            return;
        }

        // Route avec paramètre dynamique {id}
        foreach ($this->routes[$httpMethod] ?? [] as $path => $route) {
            $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                // Filtrer uniquement les captures nommées
                $params = array_filter($matches, fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
                $_REQUEST = array_merge($_REQUEST, $params);
                $this->callAction($route['controller'], $route['method']);
                return;
            }
        }

        // 404
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Route introuvable']);
    }

    private function callAction(string $controllerClass, string $method): void {
        $controller = new $controllerClass();
        $controller->$method();
    }

    private function mapLegacyAction(string $httpMethod, string $uri, string $action): ?array {
        // Mapping des anciennes URLs vers les nouvelles routes
        $mappings = [
            // Auth
            'auth' => [
                'check'           => ['path' => '/api/auth/check',           'method' => 'GET'],
                'login'           => ['path' => '/api/auth/login',           'method' => 'POST'],
                'register'        => ['path' => '/api/auth/register',        'method' => 'POST'],
                'logout'          => ['path' => '/api/auth/logout',          'method' => 'GET'],
                'request_reset'   => ['path' => '/api/auth/request-reset',   'method' => 'POST'],
                'reset_password'  => ['path' => '/api/auth/reset-password',  'method' => 'POST'],
                'update_profile'  => ['path' => '/api/auth/update-profile',  'method' => 'POST'],
            ],
            // CV
            'cv' => [
                'generate' => ['path' => '/api/cv/generate', 'method' => 'POST'],
                'improve'  => ['path' => '/api/cv/improve',  'method' => 'POST'],
                'history'  => ['path' => '/api/cv/history',  'method' => 'GET'],
                'get'      => ['path' => '/api/cv/{id}',     'method' => 'GET'],
                'delete'   => ['path' => '/api/cv/{id}',     'method' => 'DELETE'],
            ],
            // Lettre
            'lettre' => [
                'generate' => ['path' => '/api/lettre/generate', 'method' => 'POST'],
                'correct'  => ['path' => '/api/lettre/correct',  'method' => 'POST'],
                'save'     => ['path' => '/api/lettre/save',     'method' => 'POST'],
                'list'     => ['path' => '/api/lettre/list',     'method' => 'GET'],
                'get'      => ['path' => '/api/lettre/{id}',     'method' => 'GET'],
                'delete'   => ['path' => '/api/lettre/{id}',     'method' => 'POST'],
            ],
            // Entretien
            'entretien' => [
                'question'      => ['path' => '/api/entretien/question',       'method' => 'GET'],
                'analyze'       => ['path' => '/api/entretien/analyze',        'method' => 'POST'],
                'save-notes'    => ['path' => '/api/entretien/save-notes',     'method' => 'POST'],
                'list'          => ['path' => '/api/entretien/list',           'method' => 'GET'],
                'get'           => ['path' => '/api/entretien/{id}',           'method' => 'GET'],
                'delete-history'=> ['path' => '/api/entretien/delete/{id}',    'method' => 'GET'],
                'list-notes'    => ['path' => '/api/entretien/notes/list',     'method' => 'GET'],
                'get-note'      => ['path' => '/api/entretien/notes/{id}',     'method' => 'GET'],
                'delete-note'   => ['path' => '/api/entretien/notes/delete/{id}', 'method' => 'GET'],
                'delete'        => ['path' => '/api/entretien/last-answer',    'method' => 'GET'],
                'reset'         => ['path' => '/api/entretien/reset',          'method' => 'GET'],
            ],
            // Oral
            'oral' => [
                'list'   => ['path' => '/api/oral/list',   'method' => 'GET'],
                'get'    => ['path' => '/api/oral/{id}',   'method' => 'GET'],
                'delete' => ['path' => '/api/oral/delete/{id}',  'method' => 'GET'],
            ],
            // User
            'user' => [
                'save_apikey' => ['path' => '/api/user/save-apikey', 'method' => 'POST'],
                'get_apikey'  => ['path' => '/api/user/apikey',      'method' => 'GET'],
            ],
        ];

        // Déterminer la ressource depuis l'URI (ex: /api/auth.php?action=login → auth)
        $resource = null;
        if (preg_match('#/api/(\w+)(?:\.php)?#', $uri, $m)) {
            $resource = $m[1];
        }

        if ($resource && isset($mappings[$resource][$action])) {
            return $mappings[$resource][$action];
        }

        return null;
    }
}
