import { defineAsyncComponent, type Component } from "vue";

// components of all modules, loaded when they are first shown
const components = import.meta.glob<Component>(
  "../../../modules/*/resources/js/components/**/*.vue",
);

/**
 * Get a component of a module by its name "<Module>::<Component>", e.g. a dashboard tile that a
 * module registered on the server (App\Support\Extensions\DashboardTiles).
 */
export default (name: string): Component | null => {
  const [module, component] = name.split("::");
  const loader =
    components[
      `../../../modules/${module}/resources/js/components/${component}.vue`
    ];

  return loader ? defineAsyncComponent(loader) : null;
};
