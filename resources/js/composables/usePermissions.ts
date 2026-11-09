import { usePage } from "@inertiajs/vue3";

/**
 * Check permissions of the logged in user in templates. Only for showing or hiding elements,
 * the server always checks again.
 */
export default () => {
  const page = usePage<SharedProps>();

  const can = (permission: string): boolean =>
    page.props.auth?.permissions.includes(permission) ?? false;

  return { can };
};
