<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi;

use AuthKit\Auth;
use PDO;
use Psr\Container\ContainerInterface;
use rafalmasiarek\DashboardKit\Cache\QueryCacheStore;
use rafalmasiarek\DashboardKit\Cache\TableVersionTracker;
use rafalmasiarek\DashboardKit\Extension\SettingsSectionRegistry;
use rafalmasiarek\DashboardKit\Extension\UserActionRegistry;
use rafalmasiarek\DashboardKit\Flash;
use rafalmasiarek\DashboardKit\Middleware\AuthMiddleware;
use rafalmasiarek\DashboardKit\Middleware\CsrfMiddleware;
use rafalmasiarek\DashboardKit\Middleware\RoleMiddleware;
use rafalmasiarek\DashboardKit\Module\ModuleRegistry;
use rafalmasiarek\DashboardKitApi\Admin\ApiScopeRepository;
use rafalmasiarek\DashboardKitApi\Admin\UserScopeRepository;
use rafalmasiarek\DashboardKitApi\Audit\ApiTokenAuditLog;
use rafalmasiarek\DashboardKitApi\Audit\ApiTokenAuditMiddleware;
use rafalmasiarek\DashboardKitApi\Auth\DbTokenRepository;
use rafalmasiarek\DashboardKitApi\Auth\DbTokenValidator;
use rafalmasiarek\DashboardKitApi\Auth\TokenAuthMiddleware;
use rafalmasiarek\DashboardKitApi\Auth\TokenRepositoryInterface;
use rafalmasiarek\DashboardKitApi\Auth\TokenScopeMiddleware;
use rafalmasiarek\DashboardKitApi\Auth\TokenValidatorInterface;
use rafalmasiarek\DashboardKitApi\Http\JsonHandler;
use rafalmasiarek\DashboardKitApi\OpenApi\OpenApiRegistry;
use rafalmasiarek\DashboardKitApi\Schema\ApiSchemaProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Twig\Loader\FilesystemLoader;

/**
 * Wires the API token system into a Dashboard application.
 *
 * @package rafalmasiarek\DashboardKitApi
 */
final class ApiAddon
{
    /**
     * Error map for framework-level body validation failures.
     *
     * Used before the handler is invoked — not part of the route's responses declaration.
     *
     * @var array<string, array{http: int, msg: string}>
     */
    private const FRAMEWORK_ERROR_MAP = [
        'MALFORMED_JSON'     => ['http' => 400, 'msg' => 'Request body is not valid JSON.'],
        'INVALID_BODY_TYPE'  => ['http' => 400, 'msg' => 'Request body has an unexpected type.'],
        'INVALID_FIELD_TYPE' => ['http' => 422, 'msg' => 'A field has an invalid type.'],
    ];
    /**
     * Register the API addon.
     *
     * Initializes the schema, wires all DI bindings, discovers admin/user modules,
     * and registers admin UI routes, user UI routes, and versioned API routes.
     *
     * @param App                 $app
     * @param ContainerInterface  $container
     * @param array<string,mixed> $config Optional configuration overrides.
     * @return void
     */
    public static function register(App $app, ContainerInterface $container, array $config = []): void
    {
        if (!\class_exists(\rafalmasiarek\DashboardKit\Dashboard::class)) {
            throw new \LogicException(
                static::class . ' is a dashboard-kit addon and requires rafalmasiarek/dashboard-kit. '
                . 'Run: composer require rafalmasiarek/dashboard-kit'
            );
        }

        // Ensure AuthKit has run its schema migrations (creates the users table)
        // before we create user_tokens which has a FK referencing users.
        $container->get(Auth::class);

        $pdo = $container->get(PDO::class);

        if (!$container->has(TableVersionTracker::class)) {
            $rawPdo = $container->has('pdo.raw') ? $container->get('pdo.raw') : $pdo;
            $container->set(TableVersionTracker::class, static fn() => new TableVersionTracker($rawPdo));
        }

        if (!$container->has(QueryCacheStore::class)) {
            $rawPdo = $container->has('pdo.raw') ? $container->get('pdo.raw') : $pdo;
            $container->set(QueryCacheStore::class, static fn() => new QueryCacheStore($rawPdo));
        }

        $schema = new ApiSchemaProvider();
        $schema->createSchema($pdo);

        $container->set(DbTokenValidator::class, static fn() => new DbTokenValidator($pdo));
        $container->set(TokenValidatorInterface::class, static fn() => $container->get(DbTokenValidator::class));

        $container->set(DbTokenRepository::class, static fn() => new DbTokenRepository($pdo));
        $container->set(TokenRepositoryInterface::class, static fn() => $container->get(DbTokenRepository::class));

        $container->set(ApiScopeRepository::class,  static fn() => new ApiScopeRepository($pdo));
        $container->set(UserScopeRepository::class, static fn() => new UserScopeRepository($pdo));

        $container->set(ApiTokenAuditLog::class, static function () use ($container): ApiTokenAuditLog {
            try {
                $logger = $container->get('logger.api');
            } catch (\Throwable) {
                $logger = $container->get('logger.audit');
            }
            return new ApiTokenAuditLog($logger);
        });

        // --- API prefix and OpenAPI config ---
        $appConfig = (array) ($container->get('app.config')['api'] ?? []);
        $apiPrefix = \trim((string) ($appConfig['prefix'] ?? ''), '/');

        $oaConfig     = (array) ($appConfig['openapi'] ?? []);
        $oaEnabled    = (bool) ($oaConfig['enabled'] ?? true);
        $defaultOaPath = $apiPrefix !== '' ? '/' . $apiPrefix . '/openapi.json' : '/openapi.json';
        $oaPath       = (string) ($oaConfig['path']       ?? $defaultOaPath);
        $oaVisibility = (string) ($oaConfig['visibility'] ?? 'admin');

        // openapi.route_prefix controls the path prefix shown in the OpenAPI spec.
        // When set to '' the spec paths start at /v1/... and servers[].url carries the base.
        // When unset it falls back to api.prefix so existing configs without this key are unaffected.
        $oaRoutePrefix = isset($oaConfig['route_prefix'])
            ? \trim((string) $oaConfig['route_prefix'], '/')
            : $apiPrefix;

        $registry = new OpenApiRegistry();
        $registry->setApiPrefix($oaRoutePrefix);
        $registry->setInfo((array) ($oaConfig['info'] ?? ['title' => 'API', 'version' => '1.0.0']));
        $registry->setServers((array) ($oaConfig['servers'] ?? [['url' => '/']]));

        $container->set(OpenApiRegistry::class, static fn() => $registry);

        $container->get('admin_module_registry')->discover(__DIR__ . '/../modules/admin');

        $dashboardUrlPrefix  = (string) ($container->get('dashboard.url_prefix'));
        $adminPanelUrlPrefix = (string) ($container->get('dashboard.admin_panel_prefix'));
        $urlBasePath         = (string) ($container->get('app.url_base_path'));

        // Register the settings section so it appears under /settings.
        // The path is the full browser URL (including any app.base_path prefix).
        $container->get(SettingsSectionRegistry::class)->register('api-tokens', [
            'title' => 'API Tokens',
            'path'  => $urlBasePath . '/settings/api-tokens',
            'order' => 10,
        ]);

        // Register the user action so "API Scopes" button appears in admin users list.
        // The path_pattern is the full browser URL (including any app.base_path prefix).
        $container->get(UserActionRegistry::class)->register('api-scopes', [
            'label'        => 'API Scopes',
            'path_pattern' => $urlBasePath . '/admin/api/users/{id}/scopes',
            'order'        => 10,
        ]);

        // The Twig view is built by Dashboard::create() before ApiAddon::register() runs,
        // so template namespaces must be injected directly into the already-built loader.
        $view   = $container->get('view');
        $env    = $view->getEnvironment();
        $loader = $env->getLoader();
        if ($loader instanceof FilesystemLoader) {
            $loader->addPath(__DIR__ . '/../modules/admin/api-scopes/templates', 'api-scopes');
            $loader->addPath(__DIR__ . '/../modules/admin/api-tokens/templates', 'api-tokens');
            $loader->addPath(__DIR__ . '/../modules/settings/templates',         'api-settings');
            $loader->addPath(__DIR__ . '/../modules/admin/user-api-scopes/templates', 'user-api-scopes');
        }
        // Refresh Twig globals so new admin modules and extension registries are current.
        $allAdminModules = $container->get('admin_module_registry')->all();
        unset($allAdminModules['home']);
        $env->addGlobal('admin_modules', $allAdminModules);
        $adminNavPrefix = \substr($adminPanelUrlPrefix, \strlen($dashboardUrlPrefix));
        $adminNavItems  = [];
        foreach ($container->get('admin_module_registry')->navbarItems() as $slug => $m) {
            if ($slug === 'home') {
                continue;
            }
            $m['path']          = $adminNavPrefix . ($m['path'] ?? '/' . $slug);
            $m['auth_required'] = $m['auth_required'] ?? true;
            $adminNavItems[$slug] = $m;
        }
        $env->addGlobal('modules', \array_merge(
            $container->get(ModuleRegistry::class)->navbarItems(),
            $adminNavItems
        ));
        $env->addGlobal('settings_sections', $container->get(SettingsSectionRegistry::class)->all());
        $env->addGlobal('user_actions',      $container->get(UserActionRegistry::class)->all());

        // Register standard extra envelope fields for all API JSON responses.
        JsonHandler::registerExtraFields([
            'errors' => [
                'schema'    => [
                    'type'  => 'array',
                    'items' => ['type' => 'object', 'additionalProperties' => true],
                    'description' => 'Validation or domain error entries.',
                ],
                'validator' => 'errors',
                'default'   => [],
            ],
            'meta' => [
                'schema'    => [
                    'type'                 => 'object',
                    'additionalProperties' => true,
                    'description'          => 'Additional response metadata.',
                ],
                'validator' => 'data',
                'default'   => new \stdClass(),
            ],
            'pagination' => [
                'schema' => [
                    'type'       => 'object',
                    'nullable'   => true,
                    'properties' => [
                        'count'    => ['type' => 'integer', 'description' => 'Total number of records.'],
                        'page'     => ['type' => 'integer'],
                        'per_page' => ['type' => 'integer'],
                        'pages'    => ['type' => 'integer', 'description' => 'Total number of pages.'],
                        'has_more' => ['type' => 'boolean'],
                    ],
                ],
                'validator'    => 'data',
                'default'      => null,
                'omit_if_null' => true,
            ],
        ]);

        self::registerAdminRoutes($app, $container, $urlBasePath, $adminPanelUrlPrefix);
        self::registerUserRoutes($app, $container, $urlBasePath);
        self::registerApiRoutes($app, $container, $apiPrefix, $registry);

        if ($oaEnabled) {
            self::registerOpenApiRoute($app, $container, $oaPath, $oaVisibility);
        }
    }

    /**
     * Register admin UI routes for API token and scope management.
     *
     * All routes are protected by AuthMiddleware, CsrfMiddleware, and RoleMiddleware(['admin']).
     * Routes are mounted directly on $app (not via module discovery) because
     * Dashboard::registerRoutes() runs before ApiAddon::register().
     *
     * @param App                $app
     * @param ContainerInterface $container
     * @param string             $urlBasePath         Full URL base path (e.g. '/api'). Prepended to self-referential redirects.
     * @param string             $adminPanelUrlPrefix Full URL prefix for the admin panel (e.g. '/api/admin/panel'). Used for cross-section redirects.
     * @return void
     */
    private static function registerAdminRoutes(App $app, ContainerInterface $container, string $urlBasePath, string $adminPanelUrlPrefix): void
    {
        $app->group('/admin/api', function (RouteCollectorProxy $group) use ($container, $urlBasePath, $adminPanelUrlPrefix) {

            // --- API Scopes ---

            $group->get('/scopes', function ($req, $res) use ($container) {
                /** @var ApiScopeRepository $scopeRepo */
                $scopeRepo = $container->get(ApiScopeRepository::class);

                return $container->get('view')->render($res, '@api-scopes/index.twig', [
                    'title'          => 'API Scopes',
                    'breadcrumbs'    => [['label' => 'API Scopes']],
                    'scopes_grouped' => $scopeRepo->allGrouped(),
                ]);
            });

            $group->post('/scopes', function ($req, $res) use ($container, $urlBasePath) {
                $body        = (array) $req->getParsedBody();
                $category    = \trim((string) ($body['category'] ?? ''));
                $action      = \trim((string) ($body['action'] ?? ''));
                $description = \trim((string) ($body['description'] ?? ''));

                $flash = $container->get(Flash::class);

                if ($category === '' || $action === '') {
                    $flash->add('danger', 'Category and action are required.');
                    return $res->withHeader('Location', $urlBasePath . '/admin/api/scopes')->withStatus(302);
                }

                $name = $category . ':' . $action;
                /** @var ApiScopeRepository $scopeRepo */
                $scopeRepo = $container->get(ApiScopeRepository::class);
                $scopeRepo->create($name, $description !== '' ? $description : null);

                $flash->add('success', "Scope '{$name}' created.");
                return $res->withHeader('Location', $urlBasePath . '/admin/api/scopes')->withStatus(302);
            });

            $group->post('/scopes/{id}/delete', function ($req, $res, $args) use ($container, $urlBasePath) {
                $id    = (int) ($args['id'] ?? 0);
                $flash = $container->get(Flash::class);

                if ($id > 0) {
                    /** @var ApiScopeRepository $scopeRepo */
                    $scopeRepo = $container->get(ApiScopeRepository::class);
                    $scopeRepo->deleteById($id);
                    $flash->add('success', 'Scope deleted.');
                } else {
                    $flash->add('danger', 'Invalid scope ID.');
                }

                return $res->withHeader('Location', $urlBasePath . '/admin/api/scopes')->withStatus(302);
            });

            // --- API Tokens (admin: all users, all scopes) ---

            $group->get('/tokens', function ($req, $res) use ($container) {
                /** @var DbTokenRepository $tokenRepo */
                $tokenRepo = $container->get(DbTokenRepository::class);
                /** @var ApiScopeRepository $scopeRepo */
                $scopeRepo = $container->get(ApiScopeRepository::class);

                return $container->get('view')->render($res, '@api-tokens/index.twig', [
                    'title'         => 'API Tokens',
                    'breadcrumbs'   => [['label' => 'API Tokens']],
                    'tokens'        => self::normalizeTokensForView($tokenRepo->all()),
                    'scope_options' => $scopeRepo->all(),
                ]);
            });

            $group->post('/tokens', function ($req, $res) use ($container) {
                $body       = (array) $req->getParsedBody();
                $subject    = \trim((string) ($body['subject'] ?? ''));
                $ttlMinutes = (int) ($body['ttl_minutes'] ?? 0);
                $scopes     = \array_filter(\array_map('strval', (array) ($body['scopes'] ?? [])));

                $userId = (string) $container->get(Auth::class)->getUser()->get('id');
                $token  = self::generateToken();
                $exp    = $ttlMinutes > 0 ? \time() + $ttlMinutes * 60 : null;

                $claims = [
                    'user_id' => $userId,
                    'subject' => $subject !== '' ? $subject : null,
                    'exp'     => $exp,
                ];

                /** @var DbTokenRepository $tokenRepo */
                $tokenRepo = $container->get(DbTokenRepository::class);
                $tokenRepo->put($token, $claims);

                $minimized = self::minimizeScopes(\array_values($scopes));
                if ($minimized !== []) {
                    $tokenRepo->setTokenScopesByNames($token, $minimized);
                }

                $container->get(Flash::class)->add('success', 'Token created. Copy it from the list below.');
                return $res->withHeader('Location', $urlBasePath . '/admin/api/tokens')->withStatus(302);
            });

            $group->post('/tokens/{token}/revoke', function ($req, $res, $args) use ($container) {
                $token = (string) ($args['token'] ?? '');
                $flash = $container->get(Flash::class);

                if ($token !== '') {
                    /** @var DbTokenRepository $tokenRepo */
                    $tokenRepo = $container->get(DbTokenRepository::class);
                    $tokenRepo->delete($token);
                    $flash->add('success', 'Token revoked.');
                } else {
                    $flash->add('danger', 'Invalid token.');
                }

                return $res->withHeader('Location', $urlBasePath . '/admin/api/tokens')->withStatus(302);
            });

            // --- User scope assignment ---

            $group->get('/users/{userId}/scopes', function ($req, $res, $args) use ($container) {
                $targetId = (string) ($args['userId'] ?? '');

                $stmt = $container->get(PDO::class)->prepare('SELECT id, email FROM users WHERE id = :id');
                $stmt->execute([':id' => $targetId]);
                $targetUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($targetUser === null) {
                    $container->get(Flash::class)->add('danger', 'User not found.');
                    return $res->withHeader('Location', $adminPanelUrlPrefix . '/users')->withStatus(302);
                }

                /** @var ApiScopeRepository $scopeRepo */
                $scopeRepo      = $container->get(ApiScopeRepository::class);
                /** @var UserScopeRepository $userScopeRepo */
                $userScopeRepo  = $container->get(UserScopeRepository::class);

                return $container->get('view')->render($res, '@user-api-scopes/index.twig', [
                    'title'            => 'API Scopes for ' . $targetUser['email'],
                    'breadcrumbs'      => [
                        ['label' => 'Users', 'url' => $container->get('dashboard.admin_panel_prefix') . '/users'],
                        ['label' => 'API Scopes — ' . $targetUser['email']],
                    ],
                    'target_user'      => $targetUser,
                    'all_scopes'       => $scopeRepo->allGrouped(),
                    'assigned_ids'     => $userScopeRepo->scopeIdsForUser($targetId),
                ]);
            });

            $group->post('/users/{userId}/scopes', function ($req, $res, $args) use ($container) {
                $targetId  = (string) ($args['userId'] ?? '');
                $body      = (array) $req->getParsedBody();
                $scopeIds  = \array_map('intval', \array_filter((array) ($body['scope_ids'] ?? [])));

                /** @var UserScopeRepository $userScopeRepo */
                $userScopeRepo = $container->get(UserScopeRepository::class);
                $userScopeRepo->setForUser($targetId, $scopeIds);

                $container->get(Flash::class)->add('success', 'Scope assignments updated.');
                return $res->withHeader('Location', $urlBasePath . '/admin/api/users/' . $targetId . '/scopes')->withStatus(302);
            });

        })
        ->add(new RoleMiddleware($container, ['admin']))
        ->add(CsrfMiddleware::class)
        ->add(AuthMiddleware::class);
    }

    /**
     * Register user-facing routes for "My API Tokens".
     *
     * All routes are protected by AuthMiddleware and CsrfMiddleware.
     * Revoke verifies that the token belongs to the current user before deleting.
     *
     * @param App                $app
     * @param ContainerInterface $container
     * @param string             $urlBasePath Full URL base path (e.g. '/api'). Prepended to self-referential redirects.
     * @return void
     */
    private static function registerUserRoutes(App $app, ContainerInterface $container, string $urlBasePath): void
    {
        $app->group('/settings', function (RouteCollectorProxy $group) use ($container, $urlBasePath) {

            // --- API Tokens (user: own tokens, only assigned scopes) ---

            $group->get('/api-tokens', function ($req, $res) use ($container) {
                $currentUser = $container->get(Auth::class)->getUser();
                $userId      = (string) $currentUser->get('id');
                $isAdmin     = $currentUser->get('role') === 'admin';

                /** @var DbTokenRepository $tokenRepo */
                $tokenRepo = $container->get(DbTokenRepository::class);

                if ($isAdmin) {
                    /** @var ApiScopeRepository $scopeRepo */
                    $scopeOptions = $container->get(ApiScopeRepository::class)->all();
                } else {
                    /** @var UserScopeRepository $userScopeRepo */
                    $scopeOptions = $container->get(UserScopeRepository::class)->scopesForUser($userId);
                }

                return $container->get('view')->render($res, '@api-settings/tokens.twig', [
                    'title'         => 'API Tokens',
                    'breadcrumbs'   => [['label' => 'API Tokens']],
                    'tokens'        => self::normalizeTokensForView($tokenRepo->allByUser($userId)),
                    'scope_options' => $scopeOptions,
                ]);
            });

            $group->post('/api-tokens', function ($req, $res) use ($container, $urlBasePath) {
                $body       = (array) $req->getParsedBody();
                $subject    = \trim((string) ($body['subject'] ?? ''));
                $ttlMinutes = (int) ($body['ttl_minutes'] ?? 0);
                $scopes     = \array_filter(\array_map('strval', (array) ($body['scopes'] ?? [])));

                $currentUser = $container->get(Auth::class)->getUser();
                $userId      = (string) $currentUser->get('id');
                $isAdmin     = $currentUser->get('role') === 'admin';

                // Admins may use any scope; regular users are limited to their assignments.
                if (!$isAdmin) {
                    /** @var UserScopeRepository $userScopeRepo */
                    $assignedScopes = \array_column($container->get(UserScopeRepository::class)->scopesForUser($userId), 'name');
                    $scopes         = \array_values(\array_intersect(\array_values($scopes), $assignedScopes));
                }

                $token = self::generateToken();
                $exp   = $ttlMinutes > 0 ? \time() + $ttlMinutes * 60 : null;

                /** @var DbTokenRepository $tokenRepo */
                $tokenRepo = $container->get(DbTokenRepository::class);
                $tokenRepo->put($token, [
                    'user_id' => $userId,
                    'subject' => $subject !== '' ? $subject : null,
                    'exp'     => $exp,
                ]);

                $minimized = self::minimizeScopes($scopes);
                if ($minimized !== []) {
                    $tokenRepo->setTokenScopesByNames($token, $minimized);
                }

                $container->get(Flash::class)->add('success', 'Token created. Copy it from the list below.');
                return $res->withHeader('Location', $urlBasePath . '/settings/api-tokens')->withStatus(302);
            });

            $group->post('/api-tokens/{token}/revoke', function ($req, $res, $args) use ($container, $urlBasePath) {
                $token  = (string) ($args['token'] ?? '');
                $flash  = $container->get(Flash::class);
                $userId = (string) $container->get(Auth::class)->getUser()->get('id');

                if ($token === '') {
                    $flash->add('danger', 'Invalid token.');
                    return $res->withHeader('Location', $urlBasePath . '/settings/api-tokens')->withStatus(302);
                }

                /** @var DbTokenRepository $tokenRepo */
                $tokenRepo = $container->get(DbTokenRepository::class);
                $claims    = $tokenRepo->get($token);

                if ($claims === null || $claims['user_id'] !== $userId) {
                    $flash->add('danger', 'Token not found or does not belong to you.');
                    return $res->withHeader('Location', $urlBasePath . '/settings/api-tokens')->withStatus(302);
                }

                $tokenRepo->delete($token);
                $flash->add('success', 'Token revoked.');
                return $res->withHeader('Location', $urlBasePath . '/settings/api-tokens')->withStatus(302);
            });

        })
        ->add(CsrfMiddleware::class)
        ->add(AuthMiddleware::class);
    }

    /**
     * Discover modules with an 'api' key and mount versioned route groups.
     *
     * Route definition keys:
     *   scopes    — required Bearer token scopes
     *   openapi   — documentation metadata (summary, description, tags, deprecated)
     *   params    — path/query param declarations; drives type casting and OpenAPI parameters
     *   body      — JSON schema of the request body; drives OpenAPI requestBody
     *   responses — success schema and error codes; drives OpenAPI responses and runtime error map
     *   handler   — fn(array $params, mixed $body, ContainerInterface $c): array
     *
     * @param App                $app
     * @param ContainerInterface $container
     * @param string             $apiPrefix  Optional route prefix without slashes (e.g. 'api').
     * @param OpenApiRegistry    $openApiReg Registry to receive route metadata for OpenAPI spec.
     * @return void
     */
    private static function registerApiRoutes(
        App $app,
        ContainerInterface $container,
        string $apiPrefix,
        OpenApiRegistry $openApiReg,
    ): void {
        $moduleRegistry = $container->get(ModuleRegistry::class);
        $rf             = $app->getResponseFactory();

        // Resolve the validator lazily so that any TokenValidatorInterface override registered
        // after ApiAddon::register() (e.g. a CompositeTokenValidator in index.php) is used.
        $validatorProxy = new class($container) implements TokenValidatorInterface {
            public function __construct(private readonly ContainerInterface $c) {}

            /** @return array<string, mixed>|null */
            public function validate(string $token): ?array
            {
                return $this->c->get(TokenValidatorInterface::class)->validate($token);
            }
        };
        $authMw         = new TokenAuthMiddleware($validatorProxy, $rf);
        $scopeMw        = new TokenScopeMiddleware($rf);
        $resolver       = $container->has(\rafalmasiarek\RealIpResolver::class) ? $container->get(\rafalmasiarek\RealIpResolver::class) : null;
        $auditMw        = new ApiTokenAuditMiddleware($container->get(ApiTokenAuditLog::class), $resolver);

        /** @var array<string, array<int, array<string, mixed>>> $versionGroups */
        $versionGroups = [];

        foreach ($moduleRegistry->all() as $module) {
            $apiDef = $module['api'] ?? null;
            if (!\is_array($apiDef) || $apiDef === []) {
                continue;
            }

            $slug = (string) $module['slug'];

            foreach ($apiDef as $version => $routes) {
                if (!\is_array($routes) || $routes === []) {
                    continue;
                }

                $vKey = 'v' . \ltrim((string) $version, 'v');

                foreach ($routes as $endpoint => $def) {
                    if (!\is_array($def) || !isset($def['handler']) || !\is_callable($def['handler'])) {
                        continue;
                    }

                    $versionGroups[$vKey][] = [
                        'endpoint'   => $endpoint,
                        'slug'       => $slug,
                        'handler'    => $def['handler'],
                        'scopes'     => (array) ($def['scopes']    ?? []),
                        'openapi'    => (array) ($def['openapi']   ?? []),
                        'params'     => (array) ($def['params']    ?? []),
                        'body'       => $def['body']       ?? null,
                        'pagination' => isset($def['pagination']) ? (array) $def['pagination'] : null,
                        'responses'  => (array) ($def['responses'] ?? []),
                        'is_public'  => isset($def['auth']) && $def['auth'] === false,
                        'driver'     => $def['driver'] ?? null,
                        'hidden'     => (bool) ($def['hidden'] ?? false),
                    ];
                }
            }
        }

        $groupPrefix = $apiPrefix !== '' ? '/' . $apiPrefix : '';

        $buildRouteHandler = function (
            RouteCollectorProxy $group,
            array $entries,
            TokenScopeMiddleware $scopeMw,
            ContainerInterface $container,
        ): void {
            \usort($entries, static function (array $a, array $b): int {
                return \str_contains($a['endpoint'], '{') <=> \str_contains($b['endpoint'], '{');
            });

            foreach ($entries as $entry) {
                [$method, $path] = \explode(' ', $entry['endpoint'], 2);
                $fullPath         = '/' . $entry['slug'] . ($path === '/' ? '' : $path);
                $handler          = $entry['handler'];
                $params           = $entry['params'];
                $bodySchema       = $entry['body'];
                $paginationConfig = $entry['pagination'];
                $responses        = $entry['responses'];
                $responseDriver   = $entry['driver'];

                $group->map(
                    [\strtoupper($method)],
                    $fullPath,
                    function ($req, $res, $args) use ($handler, $container, $params, $bodySchema, $paginationConfig, $responses, $responseDriver) {
                        if ($bodySchema !== null) {
                            $ct  = $req->getHeaderLine('Content-Type');
                            $raw = (string) $req->getBody();
                            if (\str_contains($ct, 'application/json') && $raw !== '' && $req->getParsedBody() === null) {
                                return JsonHandler::err($res, 'MALFORMED_JSON', self::FRAMEWORK_ERROR_MAP);
                            }
                        }

                        $cast = self::castParams($args, $req->getQueryParams(), $params);

                        if ($paginationConfig !== null) {
                            $query      = $req->getQueryParams();
                            $defaultLim = (int) ($paginationConfig['default_limit'] ?? 20);
                            $maxLim     = (int) ($paginationConfig['max_limit']     ?? 100);
                            $page       = \max(1, (int) ($query['page']     ?? 1));
                            $limit      = \min($maxLim, \max(1, (int) ($query['per_page'] ?? $defaultLim)));
                            $cast['page']   = $page;
                            $cast['limit']  = $limit;
                            $cast['offset'] = ($page - 1) * $limit;
                        }

                        $parsed = $req->getParsedBody() ?? [];

                        if ($bodySchema !== null && $parsed !== []) {
                            $err = self::validateBody($bodySchema, $parsed);
                            if ($err !== null) {
                                return JsonHandler::err(
                                    $res,
                                    $err['code'],
                                    self::FRAMEWORK_ERROR_MAP,
                                    null,
                                    $err['message'] ?? null,
                                    $err['field']   ?? null,
                                    $err['detail']  ?? null,
                                );
                            }
                        }

                        $serverParams = $req->getServerParams();
                        $container->set('request.auth',          (array)  $req->getAttribute('auth', []));
                        $container->set('request.ip',            $container->has(\rafalmasiarek\RealIpResolver::class) ? $container->get(\rafalmasiarek\RealIpResolver::class)->getIp() : (string) ($serverParams['REMOTE_ADDR'] ?? ''));
                        $container->set('request.ua',            $req->getHeaderLine('User-Agent'));
                        $container->set('request.authorization', $req->getHeaderLine('Authorization'));
                        $container->set('request.psr7',          $req);

                        $result = $handler($cast, $parsed, $container);

                        $pagination = null;
                        if ($paginationConfig !== null && isset($result['total'])) {
                            $total      = \max(0, (int) $result['total']);
                            $pages      = $cast['limit'] > 0 ? (int) \ceil($total / $cast['limit']) : 1;
                            $pagination = [
                                'count'    => $total,
                                'page'     => $cast['page'],
                                'per_page' => $cast['limit'],
                                'pages'    => \max(1, $pages),
                                'has_more' => $cast['page'] < $pages,
                            ];
                        }

                        $resultArr = \is_array($result) ? $result : [];

                        if ($responseDriver !== null && !isset($resultArr['error'])) {
                            return $responseDriver->handle($res, $resultArr);
                        }

                        return self::buildApiResponse($res, $resultArr, $responses, $pagination);
                    }
                )
                ->setArgument('required_scopes', \json_encode($entry['scopes']))
                ->add($scopeMw);
            }
        };

        foreach ($versionGroups as $vKey => $entries) {
            $publicEntries    = \array_values(\array_filter($entries, static fn($e) => $e['is_public']));
            $protectedEntries = \array_values(\array_filter($entries, static fn($e) => !$e['is_public']));

            if ($protectedEntries !== []) {
                $app->group(
                    $groupPrefix . '/' . $vKey,
                    function (RouteCollectorProxy $group) use ($protectedEntries, $buildRouteHandler, $scopeMw, $container): void {
                        $buildRouteHandler($group, $protectedEntries, $scopeMw, $container);
                    }
                )->add($authMw)->add($auditMw);
            }

            if ($publicEntries !== []) {
                $app->group(
                    $groupPrefix . '/' . $vKey,
                    function (RouteCollectorProxy $group) use ($publicEntries, $buildRouteHandler, $scopeMw, $container): void {
                        $buildRouteHandler($group, $publicEntries, $scopeMw, $container);
                    }
                )->add($auditMw);
            }

            foreach ($entries as $entry) {
                if ($entry['hidden']) {
                    continue;
                }

                [$method, $path] = \explode(' ', $entry['endpoint'], 2);
                $openapi = $entry['openapi'];

                $oaParams = [];
                foreach ($entry['params'] as $name => $pDef) {
                    $param = [
                        'name'     => $name,
                        'in'       => $pDef['in'] ?? 'path',
                        'required' => $pDef['required'] ?? (($pDef['in'] ?? 'path') !== 'query'),
                        'schema'   => ['type' => $pDef['type'] ?? 'string'],
                    ];
                    if (isset($pDef['description'])) {
                        $param['description'] = (string) $pDef['description'];
                    }
                    $oaParams[] = $param;
                }

                if ($entry['pagination'] !== null) {
                    $pagConf    = $entry['pagination'];
                    $defaultLim = (int) ($pagConf['default_limit'] ?? 20);
                    $maxLim     = (int) ($pagConf['max_limit']     ?? 100);
                    $oaParams[] = [
                        'name'        => 'page',
                        'in'          => 'query',
                        'required'    => false,
                        'schema'      => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                        'description' => 'Page number (1-based).',
                    ];
                    $oaParams[] = [
                        'name'        => 'per_page',
                        'in'          => 'query',
                        'required'    => false,
                        'schema'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => $maxLim, 'default' => $defaultLim],
                        'description' => 'Number of records per page.',
                    ];
                    $openapi['_paginated'] = true;
                }

                if ($oaParams !== []) {
                    $openapi['parameters'] = $oaParams;
                }

                if ($entry['body'] !== null) {
                    $bodySchema = $entry['body'];
                    $bodyRequired = !empty($bodySchema['required']) && \is_array($bodySchema['required']) && $bodySchema['required'] !== [];
                    $openapi['requestBody'] = [
                        'required' => $bodyRequired,
                        'content'  => ['application/json' => ['schema' => $bodySchema]],
                    ];
                }

                if ($entry['responses'] !== []) {
                    $openapi['responses'] = $entry['responses'];
                }

                $openApiReg->registerRoute(
                    $method,
                    $vKey,
                    $entry['slug'],
                    $path,
                    $entry['scopes'],
                    $openapi,
                    $entry['is_public'],
                );
            }
        }
    }

    /**
     * Cast path and query parameters to their declared types.
     *
     * Path params from Slim args are always included in the output; query params
     * are included only when declared in $paramDefs. Declared params are cast to
     * their 'type' ('integer', 'number', 'boolean', or 'string').
     *
     * @param  array<string, string>             $args       Slim route arguments (path params).
     * @param  array<string, mixed>              $query      Parsed query string parameters.
     * @param  array<string, array<string,mixed>> $paramDefs  Param declarations from route def.
     * @return array<string, mixed>
     */
    private static function castParams(array $args, array $query, array $paramDefs): array
    {
        $out = $args;

        foreach ($paramDefs as $name => $def) {
            $in  = $def['in'] ?? 'path';
            $raw = $in === 'query'
                ? ($query[$name] ?? $def['default'] ?? null)
                : ($args[$name] ?? null);

            if ($raw !== null && isset($def['type'])) {
                $raw = match ($def['type']) {
                    'integer' => (int) $raw,
                    'number'  => (float) $raw,
                    'boolean' => \filter_var($raw, \FILTER_VALIDATE_BOOLEAN),
                    default   => (string) $raw,
                };
            }

            $out[$name] = $raw;
        }

        return $out;
    }

    /**
     * Build a PSR-7 response from a declarative handler result.
     *
     * Success keys: 'data' (payload), 'http' (status, default 200), 'message' (default from HTTP phrase).
     * Error keys:   'error' (symbolic code), 'message' (override), 'field', 'detail'.
     *
     * Error codes are resolved against the route's responses declaration (entries with a 'code' key).
     * For non-JSON responses, use the 'driver' key in the route definition instead.
     *
     * @param  ResponseInterface                $res
     * @param  array<string, mixed>             $result     Handler return value.
     * @param  array<int, array<string, mixed>> $responses  Route responses declaration.
     * @param  array<string, mixed>|null        $pagination Computed pagination object, or null.
     * @return ResponseInterface
     */
    private static function buildApiResponse(
        ResponseInterface $res,
        array $result,
        array $responses,
        ?array $pagination = null,
    ): ResponseInterface {
        // Keys consumed by the framework and never forwarded as response body fields.
        static $reserved = ['data', 'http', 'message', 'total', 'error', 'field', 'detail', 'headers'];

        if (isset($result['error'])) {
            $response = JsonHandler::err(
                $res,
                (string) $result['error'],
                self::buildErrorMap($responses),
                isset($result['http'])    ? (int)    $result['http']    : null,
                isset($result['message']) ? (string) $result['message'] : null,
                isset($result['field'])   ? (string) $result['field']   : null,
                isset($result['detail'])  ? (string) $result['detail']  : null,
            );
            return self::applyHeaders($response, $result['headers'] ?? []);
        }

        $http = (int) ($result['http'] ?? 200);

        // 304 Not Modified — empty body, headers only.
        if ($http === 304) {
            return self::applyHeaders($res->withStatus(304), $result['headers'] ?? []);
        }

        $message        = (string) ($result['message'] ?? JsonHandler::httpPhrase($http));
        $additionalData = $pagination !== null ? ['pagination' => $pagination] : [];

        // Forward non-reserved handler result keys as additional response body fields.
        // This enables modules to inject ad-hoc top-level fields (e.g. 'facets') without
        // requiring global extra-field registration in the framework.
        foreach ($result as $key => $value) {
            if (!\in_array($key, $reserved, true) && !\array_key_exists($key, $additionalData)) {
                $additionalData[$key] = $value;
            }
        }

        $response = JsonHandler::ok($res, $message, $result['data'] ?? [], $http, $additionalData);
        return self::applyHeaders($response, $result['headers'] ?? []);
    }

    /**
     * Apply an array of header name => value pairs to a PSR-7 response.
     *
     * @param  ResponseInterface        $response
     * @param  array<string, string>    $headers
     * @return ResponseInterface
     */
    private static function applyHeaders(ResponseInterface $response, array $headers): ResponseInterface
    {
        foreach ($headers as $name => $value) {
            $response = $response->withHeader((string) $name, (string) $value);
        }
        return $response;
    }

    /**
     * Build a JsonHandler error map from a route's responses declaration.
     *
     * Each response entry with a 'code' key becomes an error map entry:
     *   'CODE' => ['http' => <status>, 'msg' => <description>]
     *
     * @param  array<int, array<string, mixed>> $responses
     * @return array<string, array{http: int, msg: string}>
     */
    private static function buildErrorMap(array $responses): array
    {
        $map = [];
        foreach ($responses as $code => $resp) {
            if (isset($resp['code'])) {
                $map[(string) $resp['code']] = [
                    'http' => (int) $code,
                    'msg'  => (string) ($resp['description'] ?? JsonHandler::httpPhrase((int) $code)),
                ];
            }
        }
        return $map;
    }

    /**
     * Validate a parsed request body against a JSON Schema subset.
     *
     * Checks the root type and per-property types declared in 'properties'.
     * Returns an error descriptor array on failure, null on success.
     *
     * @param  array<string, mixed> $schema JSON Schema declaration from the route 'body' key.
     * @param  mixed                $parsed Parsed request body from getParsedBody().
     * @return array{code: string, message?: string, field?: string, detail?: string}|null
     */
    private static function validateBody(array $schema, mixed $parsed): ?array
    {
        $rootType = $schema['type'] ?? null;
        if ($rootType !== null && !self::checkJsonType($parsed, $rootType)) {
            return [
                'code'   => 'INVALID_BODY_TYPE',
                'detail' => 'Expected ' . $rootType . ', got ' . \gettype($parsed) . '.',
            ];
        }

        foreach ((array) ($schema['properties'] ?? []) as $field => $fieldSchema) {
            if (!\array_key_exists($field, (array) $parsed)) {
                continue;
            }
            $expectedType = $fieldSchema['type'] ?? null;
            if ($expectedType !== null && !self::checkJsonType($parsed[$field], $expectedType)) {
                return [
                    'code'   => 'INVALID_FIELD_TYPE',
                    'field'  => $field,
                    'detail' => 'Field "' . $field . '" must be of type ' . $expectedType . '.',
                ];
            }
        }

        return null;
    }

    /**
     * Check whether a PHP value matches a JSON Schema primitive type.
     *
     * Empty arrays are accepted for both 'array' and 'object' because PHP
     * cannot distinguish {} from [] after json_decode with assoc=true.
     *
     * @param  mixed  $value
     * @param  string $type  JSON Schema type: string, integer, number, boolean, array, object.
     * @return bool
     */
    private static function checkJsonType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string'  => \is_string($value),
            'integer' => \is_int($value),
            'number'  => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            'array'   => \is_array($value) && (\count($value) === 0 || \array_is_list($value)),
            'object'  => \is_array($value) && (\count($value) === 0 || !\array_is_list($value)),
            default   => true,
        };
    }

    /**
     * Mount the GET endpoint that serves the OpenAPI specification as JSON.
     *
     * Visibility determines which middleware guards the endpoint:
     *   'public'  — no authentication required
     *   'user'    — requires a valid session (AuthMiddleware)
     *   'admin'   — requires admin role (AuthMiddleware + RoleMiddleware)
     *
     * @param App                $app
     * @param ContainerInterface $container
     * @param string             $path       Slim route path, e.g. '/api/openapi.json'.
     * @param string             $visibility 'admin' | 'user' | 'public'.
     * @return void
     */
    private static function registerOpenApiRoute(
        App $app,
        ContainerInterface $container,
        string $path,
        string $visibility,
    ): void {
        $route = $app->get($path, function ($req, $res) use ($container) {
            $spec = $container->get(OpenApiRegistry::class)->build();
            $json = \json_encode($spec, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $res->getBody()->write((string) $json);
            return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
        });

        if ($visibility !== 'public') {
            if ($visibility === 'admin') {
                $route->add(new RoleMiddleware($container, ['admin']));
            }
            $route->add($container->get(AuthMiddleware::class));
        }
    }

    /**
     * Transform a raw token map from DbTokenRepository::all() / allByUser() into
     * a flat list suitable for Twig templates.
     *
     * Input shape: array<token_string, ['sub' => email, 'subject' => ?string, 'exp' => ?int, 'scopes' => string[], ...]>
     * Output shape: array<int, ['token' => string, 'email' => string, 'subject' => ?string, 'scopes' => string[], 'exp' => ?int, 'exp_h' => string]>
     *
     * @param array<string,array<string,mixed>> $raw Raw map from DbTokenRepository.
     * @return array<int, array{token: string, email: string, subject: string|null, scopes: string[], exp: int|null, exp_h: string}>
     */
    private static function normalizeTokensForView(array $raw): array
    {
        $out = [];

        foreach ($raw as $tokenStr => $claims) {
            $exp   = isset($claims['exp']) && \is_int($claims['exp']) ? $claims['exp'] : null;
            $expH  = $exp !== null ? \date('Y-m-d H:i', $exp) : '—';

            $out[] = [
                'token'   => (string) $tokenStr,
                'email'   => (string) ($claims['sub'] ?? ''),
                'subject' => isset($claims['subject']) && $claims['subject'] !== null
                    ? (string) $claims['subject']
                    : null,
                'scopes'  => (array) ($claims['scopes'] ?? []),
                'exp'     => $exp,
                'exp_h'   => $expH,
            ];
        }

        return $out;
    }

    /**
     * Remove redundant scopes when a category wildcard is already present.
     *
     * If the input contains 'notes:*', then 'notes:read' and 'notes:write' are
     * redundant and should be stripped. Wildcards of the form 'category:*' cover
     * all actions in that category.
     *
     * @param string[] $names Raw scope names as submitted.
     * @return string[]       Minimized list with redundant entries removed.
     */
    private static function minimizeScopes(array $names): array
    {
        $names = \array_values(\array_unique(\array_filter(\array_map('strval', $names))));

        // Collect all categories that have a wildcard scope present.
        $wildcardCategories = [];
        foreach ($names as $name) {
            $colonPos = \strpos($name, ':');
            if ($colonPos !== false && \substr($name, $colonPos + 1) === '*') {
                $wildcardCategories[\substr($name, 0, $colonPos)] = true;
            }
        }

        if ($wildcardCategories === []) {
            return $names;
        }

        // Filter out any non-wildcard scope whose category already has a wildcard.
        return \array_values(\array_filter($names, function (string $name) use ($wildcardCategories): bool {
            $colonPos = \strpos($name, ':');
            if ($colonPos === false) {
                return true;
            }
            $category = \substr($name, 0, $colonPos);
            $action   = \substr($name, $colonPos + 1);
            // Keep wildcard scopes themselves; remove non-wildcard siblings.
            return $action === '*' || !isset($wildcardCategories[$category]);
        }));
    }

    /**
     * Generate a cryptographically secure URL-safe base64 token.
     *
     * @param int $bytes Number of random bytes before encoding. Default: 32.
     * @return string URL-safe base64 string without padding.
     *
     * @throws \Random\RandomException When the system PRNG fails (PHP 8.2+).
     */
    private static function generateToken(int $bytes = 32): string
    {
        return \rtrim(\strtr(\base64_encode(\random_bytes($bytes)), '+/', '-_'), '=');
    }
}
