import { ref } from "vue";

type ColorMode = "light" | "dark";

// shared by all components; the mode is set before the first paint in resources/views/app.blade.php
const colorMode = ref<ColorMode>(
  document.documentElement.classList.contains("dark") ? "dark" : "light",
);

/**
 * Light and dark mode. The choice is stored in the browser; without a choice the system setting is used.
 */
export default () => {
  const setColorMode = (mode: ColorMode) => {
    colorMode.value = mode;
    document.documentElement.classList.toggle("dark", mode === "dark");
    try {
      localStorage.theme = mode;
    } catch {
      // private mode: the choice is not stored
    }
  };

  const toggleColorMode = () =>
    setColorMode(colorMode.value === "dark" ? "light" : "dark");

  return { colorMode, setColorMode, toggleColorMode };
};
