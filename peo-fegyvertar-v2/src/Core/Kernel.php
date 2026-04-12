<?php

declare(strict_types=1);

namespace Peoft\Core;

use Peoft\Admin\AdminMenu;
use Peoft\Admin\Pages\AuditViewerPage;
use Peoft\Admin\Pages\ConfigEditorPage;
use Peoft\Admin\Pages\DwhStatusPage;
use Peoft\Admin\Pages\EmailTemplateEditorPage;
use Peoft\Admin\Pages\ManualTriggerPage;
use Peoft\Admin\Pages\ReconciliationPage;
use Peoft\Admin\Pages\TasksInboxPage;
use Peoft\Admin\Rest\ConfigRestRoutes;
use Peoft\Admin\Rest\TasksRestRoutes;
use Peoft\Admin\Rest\TemplateRestRoutes;
use Peoft\Admin\Rest\TriggerRestRoutes;
use Peoft\Audit\AuditLog;
use Peoft\Audit\AuditRepository;
use Peoft\Cli\CliBootstrap;
use Peoft\Dwh\DwhRunRepository;
use Peoft\Dwh\DwhRunner;
use Peoft\Dwh\Etl\CircleExtractor as DwhCircleExtractor;
use Peoft\Dwh\Etl\KpiBuilder;
use Peoft\Dwh\Etl\SnapshotBuilder;
use Peoft\Dwh\Etl\StripeExtractor as DwhStripeExtractor;
use Peoft\Dwh\Etl\SzamlazzExtractor as DwhSzamlazzExtractor;
use Peoft\Core\Config\Config;
use Peoft\Core\Config\ConfigLoader;
use Peoft\Core\Config\ConfigRepository;
use Peoft\Core\Db\Connection;
use Peoft\Core\Db\CounterRepository;
use Peoft\Core\Db\Migrator;
use Peoft\Integrations\ActiveCampaign\ActiveCampaignClient;
use Peoft\Integrations\ActiveCampaign\ActiveCampaignClientLive;
use Peoft\Integrations\ActiveCampaign\ActiveCampaignClientMock;
use Peoft\Integrations\ActiveCampaign\TagResolver;
use Peoft\Integrations\Circle\CircleClient;
use Peoft\Integrations\Circle\CircleClientLive;
use Peoft\Integrations\Circle\CircleClientMock;
use Peoft\Integrations\Szamlazz\InvoiceBuilder;
use Peoft\Integrations\Szamlazz\PdfStore;
use Peoft\Integrations\Szamlazz\SzamlazzClient;
use Peoft\Integrations\Szamlazz\SzamlazzClientLive;
use Peoft\Integrations\Szamlazz\SzamlazzClientMock;
use Peoft\Integrations\Szamlazz\VatResolver;
use Peoft\Integrations\Szamlazz\XrefRepository;
use Peoft\Integrations\Mailer\Mailer;
use Peoft\Integrations\Mailer\MailerLive;
use Peoft\Integrations\Mailer\MailerMock;
use Peoft\Integrations\Mailer\SmtpConfig;
use Peoft\Integrations\Mailer\TemplateRenderer;
use Peoft\Integrations\Mailer\TemplateRepository;
use Peoft\Integrations\Stripe\CustomerContextLoader;
use Peoft\Integrations\Stripe\StripeClient;
use Peoft\Integrations\Stripe\StripeClientLive;
use Peoft\Integrations\Stripe\StripeClientMock;
use Peoft\Integrations\Stripe\StripeWebhookVerifier;
use Stripe\StripeClient as StripeSdkClient;
use Peoft\Orchestrator\Handlers\EnrollCircleMemberHandler;
use Peoft\Orchestrator\Handlers\IssueStornoInvoiceHandler;
use Peoft\Orchestrator\Handlers\IssueSzamlazzInvoiceHandler;
use Peoft\Orchestrator\Handlers\NoopLogOnlyHandler;
use Peoft\Orchestrator\Handlers\RevokeCircleMemberHandler;
use Peoft\Orchestrator\Handlers\SendTransactionalEmailHandler;
use Peoft\Orchestrator\Handlers\TagActiveCampaignContactHandler;
use Peoft\Orchestrator\Handlers\TaskRegistry;
use Peoft\Orchestrator\Handlers\TrialConvertToSubscriptionHandler;
use Peoft\Orchestrator\Handlers\UntagActiveCampaignContactHandler;
use Peoft\Orchestrator\Handlers\UpdateStripeCustomerVatHandler;
use Peoft\Orchestrator\Handlers\UpsertActiveCampaignContactHandler;
use Peoft\Orchestrator\Http\WebhookController;
use Peoft\Orchestrator\Queue\TaskEnqueuer;
use Peoft\Orchestrator\Queue\TaskRepository;
use Peoft\Orchestrator\Routing\EventRouter;
use Peoft\Orchestrator\Worker\Dispatcher;
use Peoft\Orchestrator\Worker\Worker;

defined('ABSPATH') || exit;

/**
 * Plugin boot orchestrator.
 *
 * Called once per request from the plugin main file via the `plugins_loaded`
 * hook (priority 1). Responsible for:
 *   1. Reading PEOFT_ENV and failing loudly if missing/invalid
 *   2. Constructing the Container and binding services
 *   3. Loading config from layered sources
 *   4. Binding the Config and AuditLog static facades
 *   5. Registering WP-CLI commands (if WP_CLI is defined)
 *
 * Does NOT run migrations. Migrations run on plugin activation via onActivate().
 */
final class Kernel
{
    private static ?Container $container = null;
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        try {
            $env = Env::fromConstant();
        } catch (\Throwable $e) {
            self::adminNotice('PEO Fegyvertár 2.0: ' . $e->getMessage());
            return;
        }

        global $wpdb;
        $container = new Container();
        $container->instance(\wpdb::class, $wpdb);
        $container->instance(Env::class, $env);

        $container->bind(Clock::class, static fn () => new Clock());

        $container->bind(Connection::class, static function (Container $c): Connection {
            return new Connection($c->get(\wpdb::class));
        });

        $container->bind(Migrator::class, static function (Container $c): Migrator {
            return new Migrator($c->get(Connection::class));
        });

        $container->bind(AuditRepository::class, static function (Container $c): AuditRepository {
            return new AuditRepository($c->get(Connection::class));
        });

        // Load config. If the DB layer fails (table missing because plugin
        // isn't activated yet), ConfigLoader returns empty for that layer.
        $container->bind(ConfigRepository::class, static function (Container $c) use ($env): ConfigRepository {
            $loader = new ConfigLoader($env, $c->get(\wpdb::class));
            $values = $loader->load();
            return new ConfigRepository($env, $values, $c->get(\wpdb::class));
        });

        // Realize + bind facades.
        Config::bind($container->get(ConfigRepository::class));
        AuditLog::bind(
            $container->get(AuditRepository::class),
            $container->get(Clock::class)
        );

        // ---- PROD-leak guard (§16 item 17) ----
        // Now that Config is loaded, check that the Stripe key type matches
        // the env. Prevents DEV from using live keys (would charge real
        // customers) and PROD from using test keys (webhooks would fail).
        $stripeKey = (string) Config::for('stripe')->get('secret_key', '');
        if ($stripeKey !== '') {
            $isLiveKey = str_starts_with($stripeKey, 'sk_live_') || str_starts_with($stripeKey, 'rk_live_');
            $isTestKey = str_starts_with($stripeKey, 'sk_test_') || str_starts_with($stripeKey, 'rk_test_');
            if (!$env->isProd() && $isLiveKey) {
                Config::reset();
                AuditLog::reset();
                self::adminNotice('PEO Fegyvertár 2.0: REFUSING TO BOOT — non-prod env (' . $env->value . ') has a live Stripe key (sk_live_…). This would charge real customers from DEV/UAT. Remove the live key or switch PEOFT_ENV to prod.');
                return;
            }
            if ($env->isProd() && $isTestKey) {
                Config::reset();
                AuditLog::reset();
                self::adminNotice('PEO Fegyvertár 2.0: REFUSING TO BOOT — PROD env has a test Stripe key (sk_test_…). Real webhooks will fail signature verification. Set the live key.');
                return;
            }
        }

        // Phase B: orchestrator services.
        $container->bind(TaskRepository::class, static function (Container $c): TaskRepository {
            return new TaskRepository($c->get(Connection::class));
        });
        $container->bind(TaskEnqueuer::class, static function (Container $c): TaskEnqueuer {
            return new TaskEnqueuer($c->get(Connection::class));
        });
        $container->bind(StripeWebhookVerifier::class, static fn () => new StripeWebhookVerifier());
        $container->bind(EventRouter::class, static function (Container $c) use ($env): EventRouter {
            return new EventRouter($env);
        });
        $container->bind(TemplateRepository::class, static function (Container $c) use ($env): TemplateRepository {
            return new TemplateRepository($c->get(Connection::class), $env);
        });
        $container->bind(TemplateRenderer::class, static function (Container $c): TemplateRenderer {
            return new TemplateRenderer($c->get(TemplateRepository::class));
        });
        $container->bind(SmtpConfig::class, static function (): SmtpConfig {
            $m = Config::for('mailer');
            return new SmtpConfig(
                host:       (string) $m->get('host', ''),
                port:       (int) $m->get('port', 587),
                encryption: (string) $m->get('encryption', 'tls'),
                username:   (string) $m->get('username', ''),
                password:   (string) $m->get('password', ''),
                from:       (string) $m->get('from', 'noreply@localhost'),
                fromName:   (string) $m->get('from_name', 'Fegyvertár'),
                replyTo:    $m->get('reply_to') !== null ? (string) $m->get('reply_to') : null,
                bcc:        $m->get('bcc') !== null ? (string) $m->get('bcc') : null,
            );
        });
        // Mode-based Mailer resolution: live/mock picked at container bind time
        // from `mailer.mode` config. DEV ships with mock by default (set by
        // ImportFromLegacyDbCommand safety override).
        $container->bind(Mailer::class, static function (Container $c): Mailer {
            $mode = Config::for('mailer')->mode();
            return match ($mode) {
                'mock'  => new MailerMock($c->get(TemplateRenderer::class)),
                default => new MailerLive(
                    $c->get(SmtpConfig::class),
                    $c->get(TemplateRenderer::class),
                ),
            };
        });
        // Phase C2: ActiveCampaign services.
        $container->bind(TagResolver::class, static function (Container $c) use ($env): TagResolver {
            return new TagResolver(
                env: $env,
                configFn: static function (): array {
                    $s = Config::for('activecampaign');
                    return [
                        'api_url'   => (string) $s->get('api_url', ''),
                        'api_token' => (string) $s->get('api_key', ''),
                    ];
                },
            );
        });
        $container->bind(ActiveCampaignClient::class, static function (Container $c): ActiveCampaignClient {
            $mode = Config::for('activecampaign')->mode();
            if ($mode === 'mock') {
                return new ActiveCampaignClientMock();
            }
            $s = Config::for('activecampaign');
            return new ActiveCampaignClientLive(
                apiUrl:   (string) $s->get('api_url', ''),
                apiToken: (string) $s->get('api_key', ''),
                tags:     $c->get(TagResolver::class),
            );
        });

        // Phase C3: Circle.
        $container->bind(CircleClient::class, static function (): CircleClient {
            $mode = Config::for('circle')->mode();
            if ($mode === 'mock') {
                return new CircleClientMock();
            }
            return new CircleClientLive(
                apiKey: (string) Config::for('circle')->get('v2_api_key', ''),
            );
        });

        // Phase C4: Számlázz.
        $container->bind(VatResolver::class, static fn () => new VatResolver());
        $container->bind(InvoiceBuilder::class, static function (Container $c): InvoiceBuilder {
            return new InvoiceBuilder($c->get(VatResolver::class));
        });
        $container->bind(PdfStore::class, static function (): PdfStore {
            // `wp-content/peoft-private/invoices/` — OUTSIDE `uploads/` so
            // Apache doesn't serve PDFs by direct URL. Phase D adds an
            // authenticated admin download endpoint.
            return new PdfStore(
                basePath: WP_CONTENT_DIR . '/peoft-private/invoices',
            );
        });
        $container->bind(XrefRepository::class, static function (Container $c) use ($env): XrefRepository {
            return new XrefRepository($c->get(Connection::class), $env);
        });
        $container->bind(CounterRepository::class, static function (Container $c) use ($env): CounterRepository {
            return new CounterRepository($c->get(Connection::class), $env);
        });
        $container->bind(SzamlazzClient::class, static function (Container $c): SzamlazzClient {
            $mode = Config::for('szamlazz')->mode();
            // Phase C4: both 'mock' and 'demo' resolve to Mock. Real
            // demo-endpoint testing via SzamlaAgent's $testMode flag is a
            // Phase G follow-up when we have a demo account provisioned.
            if ($mode === 'mock' || $mode === 'demo') {
                return new SzamlazzClientMock();
            }
            return new SzamlazzClientLive(
                apiKey: (string) Config::for('szamlazz')->get('api_key', ''),
                builder: $c->get(InvoiceBuilder::class),
                pdfStore: $c->get(PdfStore::class),
            );
        });

        // Phase C5: Stripe (read-side).
        $container->bind(StripeClient::class, static function (): StripeClient {
            $mode = Config::for('stripe')->mode();
            if ($mode === 'mock') {
                return new StripeClientMock();
            }
            $secretKey = (string) Config::for('stripe')->get('secret_key', '');
            if ($secretKey === '') {
                // No key configured — fall back to mock so we never
                // silently hit the wrong Stripe account.
                return new StripeClientMock();
            }
            return new StripeClientLive(new StripeSdkClient($secretKey));
        });
        $container->bind(CustomerContextLoader::class, static function (Container $c): CustomerContextLoader {
            return new CustomerContextLoader($c->get(StripeClient::class));
        });

        $container->bind(TaskRegistry::class, static function (Container $c): TaskRegistry {
            $registry = new TaskRegistry();
            // Placeholder for events that don't yet have real handlers.
            $registry->register(new NoopLogOnlyHandler());
            // Phase C1 + C5: real email handler with Stripe customer enrichment.
            $registry->register(new SendTransactionalEmailHandler(
                $c->get(Mailer::class),
                $c->get(TemplateRepository::class),
                $c->get(CustomerContextLoader::class),
            ));
            // Phase C2: ActiveCampaign handlers.
            $ac = $c->get(ActiveCampaignClient::class);
            $registry->register(new UpsertActiveCampaignContactHandler($ac));
            $registry->register(new TagActiveCampaignContactHandler($ac));
            $registry->register(new UntagActiveCampaignContactHandler($ac));
            // Phase C3: Circle handlers.
            $circle = $c->get(CircleClient::class);
            $registry->register(new EnrollCircleMemberHandler($circle));
            $registry->register(new RevokeCircleMemberHandler($circle));
            // Phase C4: Számlázz handlers.
            $szamlazz = $c->get(SzamlazzClient::class);
            $xref = $c->get(XrefRepository::class);
            $registry->register(new IssueSzamlazzInvoiceHandler(
                client: $szamlazz,
                xref: $xref,
                counters: $c->get(CounterRepository::class),
                invoicePrefix: (string) Config::for('szamlazz')->get('prefix', 'FEGY'),
            ));
            $registry->register(new IssueStornoInvoiceHandler(
                client: $szamlazz,
                xref: $xref,
            ));
            // Phase C5: Stripe read-side handlers.
            $registry->register(new UpdateStripeCustomerVatHandler(
                $c->get(CustomerContextLoader::class),
            ));
            $registry->register(new TrialConvertToSubscriptionHandler(
                $c->get(StripeClient::class),
            ));
            return $registry;
        });
        $container->bind(Dispatcher::class, static function (Container $c): Dispatcher {
            return new Dispatcher(
                $c->get(TaskRepository::class),
                $c->get(TaskRegistry::class)
            );
        });
        $container->bind(Worker::class, static function (Container $c): Worker {
            return new Worker(
                $c->get(TaskRepository::class),
                $c->get(Dispatcher::class)
            );
        });
        $container->bind(WebhookController::class, static function (Container $c) use ($env): WebhookController {
            return new WebhookController(
                $c->get(Connection::class),
                $env,
                $c->get(StripeWebhookVerifier::class),
                $c->get(EventRouter::class),
                $c->get(TaskEnqueuer::class)
            );
        });

        // Register the Stripe webhook REST route + admin mutation routes
        // on rest_api_init.
        add_action('rest_api_init', static function () use ($container): void {
            $container->get(WebhookController::class)->registerRoute();
            $container->get(TasksRestRoutes::class)->register();
            $container->get(ConfigRestRoutes::class)->register();
            $container->get(TemplateRestRoutes::class)->register();
            $container->get(TriggerRestRoutes::class)->register();
        });

        // Phase D: admin UI bindings.
        $container->bind(TasksRestRoutes::class, static function (Container $c): TasksRestRoutes {
            return new TasksRestRoutes(
                tasks: $c->get(\Peoft\Orchestrator\Queue\TaskRepository::class),
                worker: $c->get(\Peoft\Orchestrator\Worker\Worker::class),
            );
        });
        $container->bind(ConfigRestRoutes::class, static function (Container $c): ConfigRestRoutes {
            return new ConfigRestRoutes(
                repo: $c->get(ConfigRepository::class),
                db:   $c->get(Connection::class),
            );
        });
        $container->bind(TemplateRestRoutes::class, static function (Container $c) use ($env): TemplateRestRoutes {
            return new TemplateRestRoutes(
                db:        $c->get(Connection::class),
                envEnum:   $env,
                templates: $c->get(\Peoft\Integrations\Mailer\TemplateRepository::class),
            );
        });
        $container->bind(TriggerRestRoutes::class, static function (Container $c) use ($env): TriggerRestRoutes {
            return new TriggerRestRoutes(
                enqueuer: $c->get(\Peoft\Orchestrator\Queue\TaskEnqueuer::class),
                registry: $c->get(\Peoft\Orchestrator\Handlers\TaskRegistry::class),
                envEnum:  $env,
            );
        });
        $container->bind(TasksInboxPage::class, static function (Container $c) use ($env): TasksInboxPage {
            return new TasksInboxPage($c, $env);
        });
        $container->bind(AuditViewerPage::class, static function (Container $c) use ($env): AuditViewerPage {
            return new AuditViewerPage($c, $env);
        });
        $container->bind(ReconciliationPage::class, static function (Container $c) use ($env): ReconciliationPage {
            return new ReconciliationPage($c, $env);
        });
        $container->bind(ManualTriggerPage::class, static function (Container $c) use ($env): ManualTriggerPage {
            return new ManualTriggerPage($c, $env);
        });
        $container->bind(ConfigEditorPage::class, static function (Container $c) use ($env): ConfigEditorPage {
            return new ConfigEditorPage($c, $env);
        });
        $container->bind(EmailTemplateEditorPage::class, static function (Container $c) use ($env): EmailTemplateEditorPage {
            return new EmailTemplateEditorPage($c, $env);
        });
        $container->bind(DwhStatusPage::class, static function (Container $c) use ($env): DwhStatusPage {
            return new DwhStatusPage($c, $env);
        });

        // Register admin menu + pages. Order determines menu display order
        // and which page the top-level slug opens.
        if (is_admin()) {
            $menu = new AdminMenu($container);
            $menu->register(TasksInboxPage::class);         // lands on the top-level slug
            $menu->register(ReconciliationPage::class);
            $menu->register(AuditViewerPage::class);
            $menu->register(ManualTriggerPage::class);
            $menu->register(ConfigEditorPage::class);
            $menu->register(EmailTemplateEditorPage::class);
            $menu->register(DwhStatusPage::class);
            $menu->hook();
        }

        // Phase E: DWH service bindings. Completely isolated from the
        // orchestrator — uses its own Stripe SDK client from config, never
        // touches peoft_tasks / peoft_webhook_events.
        $container->bind(DwhRunRepository::class, static function (Container $c): DwhRunRepository {
            return new DwhRunRepository($c->get(Connection::class));
        });
        $container->bind(DwhStripeExtractor::class, static function (Container $c): DwhStripeExtractor {
            return new DwhStripeExtractor($c->get(Connection::class));
        });
        $container->bind(DwhSzamlazzExtractor::class, static function (Container $c): DwhSzamlazzExtractor {
            return new DwhSzamlazzExtractor($c->get(Connection::class));
        });
        $container->bind(DwhCircleExtractor::class, static function (Container $c): DwhCircleExtractor {
            return new DwhCircleExtractor($c->get(Connection::class));
        });
        $container->bind(SnapshotBuilder::class, static function (Container $c): SnapshotBuilder {
            return new SnapshotBuilder($c->get(Connection::class));
        });
        $container->bind(KpiBuilder::class, static function (Container $c): KpiBuilder {
            return new KpiBuilder($c->get(Connection::class));
        });
        $container->bind(DwhRunner::class, static function (Container $c): DwhRunner {
            return new DwhRunner(
                runs:      $c->get(DwhRunRepository::class),
                stripe:    $c->get(DwhStripeExtractor::class),
                szamlazz:  $c->get(DwhSzamlazzExtractor::class),
                circle:    $c->get(DwhCircleExtractor::class),
                snapshots: $c->get(SnapshotBuilder::class),
                kpi:       $c->get(KpiBuilder::class),
            );
        });

        self::$container = $container;
        self::$booted = true;

        if (defined('WP_CLI') && WP_CLI) {
            CliBootstrap::register($container);
        }
    }

    public static function container(): Container
    {
        if (self::$container === null) {
            throw new \RuntimeException('Kernel::container() called before boot.');
        }
        return self::$container;
    }

    /**
     * Activation hook — creates all plugin tables. Runs synchronously.
     * Refuses to proceed if PEOFT_ENV is missing so nothing half-initializes.
     */
    public static function onActivate(): void
    {
        if (!defined('PEOFT_ENV')) {
            wp_die(
                '<h1>PEO Fegyvertár 2.0</h1><p>Activation refused: <code>PEOFT_ENV</code> is not defined in <code>wp-config.php</code>. Add <code>define(\'PEOFT_ENV\', \'dev\');</code> and retry.</p>',
                'PEO Fegyvertár 2.0 activation error',
                ['response' => 500, 'back_link' => true]
            );
        }
        try {
            Env::fromConstant();
        } catch (\Throwable $e) {
            wp_die(
                '<h1>PEO Fegyvertár 2.0</h1><p>Activation refused: ' . esc_html($e->getMessage()) . '</p>',
                'PEO Fegyvertár 2.0 activation error',
                ['response' => 500, 'back_link' => true]
            );
        }

        global $wpdb;
        $db = new Connection($wpdb);
        $migrator = new Migrator($db);
        $applied = $migrator->up();

        // Best-effort audit of the activation. AuditLog may not be bound yet
        // (activation runs before plugins_loaded fires boot), so we set up a
        // minimal binding just for this call.
        $clock = new Clock();
        AuditLog::bind(new AuditRepository($db), $clock);
        AuditLog::record(
            actor: 'cli',
            action: 'PLUGIN_ACTIVATED',
            subjectType: 'plugin',
            subjectId: 'peo-fegyvertar-v2',
            after: [
                'migrations_applied' => $applied,
                'php_version'        => PHP_VERSION,
                'wp_version'         => function_exists('get_bloginfo') ? get_bloginfo('version') : null,
            ],
        );
    }

    public static function onDeactivate(): void
    {
        global $wpdb;
        $db = new Connection($wpdb);
        AuditLog::bind(new AuditRepository($db), new Clock());
        AuditLog::record(
            actor: 'cli',
            action: 'PLUGIN_DEACTIVATED',
            subjectType: 'plugin',
            subjectId: 'peo-fegyvertar-v2',
        );
    }

    private static function adminNotice(string $message): void
    {
        add_action('admin_notices', static function () use ($message): void {
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        });
    }
}
