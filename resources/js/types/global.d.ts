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
    flash: Record<"success" | "error" | "warning" | "info", string | null>;
    errors: Record<string, string>;
    serverTime: number;
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
    children?: NavigationEntry[];
  }

  interface Option {
    value: string | number;
    label: string;
  }
}
