import { defineAsyncComponent, type Component } from "vue";

// components of the core and of all modules, loaded when they are first shown
const coreComponents = import.meta.glob<Component>("../components/**/*.vue");
const components = import.meta.glob<Component>(
  "../../../modules/*/resources/js/components/**/*.vue",
);

/**
 * Get a component by the name a module or the core registered on the server, e.g. a dashboard
 * tile (App\Support\Extensions\DashboardTiles). Components of modules are named
 * "<Module>::<Component>", those of the core by their path in resources/js/components.
 */
export default (name: string): Component | null => {
  const [module, component] = name.includes("::")
    ? name.split("::")
    : [null, name];
  const loader = module
    ? components[
        `../../../modules/${module}/resources/js/components/${component}.vue`
      ]
    : coreComponents[`../components/${component}.vue`];

  return loader ? defineAsyncComponent(loader) : null;
};
