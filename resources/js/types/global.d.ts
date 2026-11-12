export {};

declare global {
  const __PACKAGE_NAME__: string;
  const __PACKAGE_REPOSITORY_URL__: string;

  /**
   * Props every page gets, see app/Http/Middleware/HandleInertiaRequests.php.
   */
  interface SharedProps {
    auth: {
      user: {
        id: number;
        firstname: string;
        lastname: string;
        email: string;
        name: string;
      };
      permissions: string[];
    } | null;
    navigation: NavigationSection[];
    organization: {
      name: string;
      short_name: string;
      accent_color: string;
      // null while no logo was uploaded (Admin › Einstellungen › Allgemein)
      logo_url: string | null;
      imprint_url: string | null;
      privacy_url: string | null;
    };
    flash: Record<"success" | "error" | "warning" | "info", string | null> & {
      // item with a new number whose label can be printed right away
      label?: { id: number; number: string } | null;
    };
    errors: Record<string, string>;
    serverTime: number;
    // surface of the page (e.g. the kiosk), null on the pc and the phone; see App\Support\Surface
    surface: Surface | null;
  }

  interface NavigationSection {
    key: string;
    title: string;
    entries: NavigationEntry[];
  }

  interface NavigationEntry {
    title: string;
    // null for a group, whose entries are in children
    href: string | null;
    icon: string;
    mobile: boolean;
    // short status beside the title (App\Support\Extensions\Navigation::add)
    badge?: {
      text: string;
      variant: "neutral" | "accent" | "success" | "warning" | "danger";
    } | null;
    children?: NavigationEntry[];
  }

  interface Surface {
    name: string;
    // path prefix, e.g. "/kiosk"
    base: string;
    layout: string;
    platform?: string;
    // false: no direct printing on this device; null or missing: the browser decides
    direct_print?: boolean | null;
    person?: { id: number; firstname: string; lastname: string } | null;
    // seconds until the surface locks itself
    expires_in?: number | null;
    lock_after_task?: boolean;
  }

  interface Option {
    value: string | number;
    label: string;
  }
}
