<?php

declare(strict_types=1);

namespace Peoft\Admin;

use Peoft\Core\Container;

defined('ABSPATH') || exit;

/**
 * Registers the `PEO Fegyvertár` top-level menu + its submenu pages.
 *
 * Hooked into `admin_menu` by Kernel::boot(). Each registered page is an
 * `AdminPage` subclass; the menu callback resolves the page from the
 * container (request-scoped lazy bind) and delegates to `page->render()`.
 *
 * Capability for every page: `manage_options` (WP administrators).
 * Phase D item 31 in the security checklist flags this as intentional for
 * the current team size.
 */
final class AdminMenu
{
    public const TOP_SLUG = 'peo-fegyvertar';
    public const CAPABILITY = 'manage_options';

    /** @var list<class-string<AdminPage>> */
    private array $pages = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param class-string<AdminPage> $pageClass
     */
    public function register(string $pageClass): void
    {
        $this->pages[] = $pageClass;
    }

    public function hook(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
    }

    public function registerMenu(): void
    {
        // Top-level menu — lands on the Tasks Inbox page (first registered).
        $firstPageClass = $this->pages[0] ?? null;
        if ($firstPageClass === null) {
            return;
        }

        add_menu_page(
            'PEO Fegyvertár',
            'PEO Fegyvertár',
            self::CAPABILITY,
            self::TOP_SLUG,
            function () use ($firstPageClass): void {
                /** @var AdminPage $page */
                $page = $this->container->get($firstPageClass);
                $page->render();
            },
            'dashicons-shield-alt',
            30
        );

        foreach ($this->pages as $i => $pageClass) {
            $slug = $pageClass::slug();
            // Skip the first page in the submenu loop — it's already
            // registered as the top-level add_menu_page callback above.
            // WP auto-creates a submenu entry for the top-level slug;
            // we just rename its label below. Registering it again would
            // cause the page to render twice (double env banner).
            if ($i === 0) {
                continue;
            }
            add_submenu_page(
                self::TOP_SLUG,
                'PEO Fegyvertár',
                $this->menuLabel($pageClass),
                self::CAPABILITY,
                $slug,
                function () use ($pageClass): void {
                    /** @var AdminPage $page */
                    $page = $this->container->get($pageClass);
                    $page->render();
                }
            );
        }

        // Replace the auto-generated "PEO Fegyvertár" first submenu entry
        // (which WP always creates equal to the top-level slug) with a
        // proper label. The entry points at the same first page.
        global $submenu;
        if (isset($submenu[self::TOP_SLUG][0][0])) {
            $submenu[self::TOP_SLUG][0][0] = $this->menuLabel($firstPageClass);
        }
    }

    /**
     * @param class-string<AdminPage> $pageClass
     */
    private function menuLabel(string $pageClass): string
    {
        // Instantiate just to get the menu label. Cheap because these
        // classes are thin and the container caches the instance.
        /** @var AdminPage $page */
        $page = $this->container->get($pageClass);
        return $page->menuTitle();
    }
}
