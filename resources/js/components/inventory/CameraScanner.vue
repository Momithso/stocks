<template>
  <!-- picture on top, every control in one row below it, so nothing covers the square -->
  <div class="flex flex-col gap-2">
    <div class="relative min-h-0 flex-1 overflow-hidden rounded-lg bg-black">
      <video
        v-show="running"
        ref="video"
        class="h-full w-full object-cover"
        playsinline
        muted
        @click="auto || scanOnce()"
      />

      <!-- viewfinder -->
      <div
        v-if="running"
        class="pointer-events-none absolute inset-0 flex items-center justify-center"
      >
        <div
          ref="frame"
          :class="[
            'aspect-square h-3/5 max-h-80 rounded-xl border-4 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)] transition-colors',
            searching ? 'border-accent' : 'border-white/80',
          ]"
        />
      </div>
      <p
        v-if="running && notice"
        class="pointer-events-none absolute inset-x-0 bottom-2 mx-auto w-fit rounded-full bg-black/60 px-3 py-1.5 text-sm text-white"
      >
        {{ notice }}
      </p>

      <div
        v-if="!running"
        class="flex h-full min-h-[12rem] flex-col items-center justify-center gap-3 p-6 text-center text-white"
      >
        <p v-if="error" class="max-w-sm text-sm">{{ error }}</p>
        <UiButton icon="camera" :disabled="starting" @click="start()">
          {{ starting ? "Kamera startet …" : "Kamera einschalten" }}
        </UiButton>
      </div>
    </div>

    <!-- bar like the bottom navigation: "Scannen" big in the middle, the rest smaller on both sides -->
    <nav
      v-if="running"
      class="grid grid-cols-[1fr_auto_1fr] items-center gap-2"
      aria-label="Kamera"
    >
      <div :class="side">
        <button
          type="button"
          role="switch"
          :aria-checked="auto"
          :class="item"
          @click="setAuto(!auto)"
        >
          <span
            :class="[
              'flex h-4 w-7 items-center rounded-full p-0.5 transition-colors',
              auto ? 'bg-accent' : 'bg-line',
            ]"
          >
            <span
              :class="[
                'h-3 w-3 rounded-full bg-white shadow transition-transform',
                auto ? 'translate-x-3' : '',
              ]"
            />
          </span>
          Automatisch
        </button>
        <button
          v-if="torchAvailable"
          type="button"
          :class="[item, torch ? 'text-yellow-500' : '']"
          :aria-pressed="torch"
          @click="toggleTorch"
        >
          <FontAwesomeIcon :icon="['fas', 'bolt']" class="h-4" /> Licht
        </button>
      </div>

      <!-- also with "Automatisch" on: reads the square right away, even the same code again -->
      <button
        type="button"
        class="flex h-16 w-24 flex-col items-center justify-center gap-1 rounded-2xl bg-accent text-sm font-medium text-white shadow-md disabled:opacity-70"
        :disabled="searching"
        @click="scanOnce"
      >
        <FontAwesomeIcon :icon="['fas', 'qrcode']" class="h-6" />
        {{ searching ? "Sucht …" : "Scannen" }}
      </button>

      <div :class="side">
        <button
          v-if="cameras.length > 1"
          type="button"
          :class="item"
          @click="nextCamera"
        >
          <FontAwesomeIcon :icon="['fas', 'camera-rotate']" class="h-4" />
          Wechseln
        </button>
        <!-- entries of the page, e.g. "Kamera groß" on the scan page -->
        <slot name="actions" :item="item" />
        <button type="button" :class="item" @click="stop">
          <FontAwesomeIcon :icon="['fas', 'xmark']" class="h-4" /> Aus
        </button>
      </div>
    </nav>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";

/**
 * Camera scan of qr codes and data matrix codes with barcode-detector (ZXing). The WebAssembly
 * file is part of the build, nothing is loaded from other servers. The same code only fires again
 * after a pause; every hit beeps and vibrates. Only the part inside the square is read, either all the
 * time ("Automatisch") or once per tap on the picture or the button. The controls sit in a bar below
 * the picture; pages add their own through the slot "actions".
 */
const props = withDefaults(defineProps<{ autostart?: boolean }>(), {
  autostart: false,
});
const emit = defineEmits<{ scan: [code: string]; started: []; stopped: [] }>();

const video = ref<HTMLVideoElement | null>(null);
const frame = ref<HTMLDivElement | null>(null);
const running = ref(false);
const starting = ref(false);
const error = ref<string | null>(null);
const cameras = ref<MediaDeviceInfo[]>([]);
const cameraIndex = ref(0);
const torchAvailable = ref(false);
const torch = ref(false);
const searching = ref(false);
// the smaller entries on both sides of "Scannen", also handed to the "actions" slot
const side =
  "flex h-12 divide-x divide-line overflow-hidden rounded-xl border border-line bg-surface shadow-sm";
const item =
  "flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-1 text-[11px] leading-tight text-ink hover:bg-surface-muted";
const notice = ref<string | null>(null);

// automatic scanning or one try per tap; remembered on this device
const readAuto = () => {
  try {
    return localStorage.getItem("scanAuto") !== "0";
  } catch {
    return true;
  }
};
const auto = ref(readAuto());
const setAuto = (on: boolean) => {
  auto.value = on;
  notice.value = null;
  try {
    localStorage.setItem("scanAuto", on ? "1" : "0");
  } catch {
    // private mode: not remembered
  }
};

let stream: MediaStream | null = null;
let detector: {
  detect: (source: HTMLCanvasElement) => Promise<{ rawValue: string }[]>;
} | null = null;
let timer = 0;
let lastCode = "";
let lastAt = 0;

// the same code fires again only after this pause
const REPEAT_MS = 2500;

const createDetector = async () => {
  const [{ BarcodeDetector, prepareZXingModule }, { default: wasmUrl }] =
    await Promise.all([
      import("barcode-detector/ponyfill"),
      import("zxing-wasm/reader/zxing_reader.wasm?url"),
    ]);
  prepareZXingModule({
    overrides: {
      locateFile: (path: string, prefix: string) =>
        path.endsWith(".wasm") ? wasmUrl : prefix + path,
    },
  });
  return new BarcodeDetector({ formats: ["qr_code", "data_matrix"] });
};

const start = async (deviceId?: string) => {
  error.value = null;
  if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
    error.value =
      "Die Kamera geht nur über HTTPS. Scanne mit dem Handscanner oder tippe den Code ein.";
    return;
  }
  starting.value = true;
  try {
    detector ??= await createDetector();
    stream = await navigator.mediaDevices.getUserMedia({
      video: deviceId
        ? { deviceId: { exact: deviceId } }
        : { facingMode: { ideal: "environment" } },
      audio: false,
    });
    video.value!.srcObject = stream;
    await video.value!.play();
    running.value = true;
    emit("started");

    cameras.value = (await navigator.mediaDevices.enumerateDevices()).filter(
      (device) => device.kind === "videoinput",
    );
    const track = stream.getVideoTracks()[0];
    torchAvailable.value = !!(
      track.getCapabilities?.() as { torch?: boolean } | undefined
    )?.torch;
    torch.value = false;
    loop();
  } catch (exception) {
    stopStream();
    const name = (exception as DOMException)?.name;
    error.value =
      name === "NotAllowedError"
        ? "Der Browser darf die Kamera nicht nutzen. Erlaube die Kamera in den Einstellungen der Seite (Schloss-Symbol neben der Adresse) und versuche es noch einmal, oder tippe den Code unten ein."
        : name === "NotFoundError"
          ? "Keine Kamera gefunden. Tippe den Code unten ein oder nutze den Handscanner."
          : "Die Kamera ließ sich nicht starten.";
  } finally {
    starting.value = false;
  }
};

// only the part of the picture inside the square is read, so codes next to it are ignored
const canvas = document.createElement("canvas");
const context = canvas.getContext("2d", { willReadFrequently: true });
const insideFrame = (): HTMLCanvasElement | null => {
  const element = video.value!;
  const { videoWidth: width, videoHeight: height } = element;
  if (!frame.value || !context || !width || !height) {
    return null;
  }
  const box = element.getBoundingClientRect();
  const square = frame.value.getBoundingClientRect();
  // the video is shown with object-cover: scaled up and cut off at two sides
  const scale = Math.max(box.width / width, box.height / height);
  const left = (box.width - width * scale) / 2;
  const top = (box.height - height * scale) / 2;
  const size = square.width / scale;
  canvas.width = canvas.height = Math.min(Math.round(size), 800);
  context.drawImage(
    element,
    (square.left - box.left - left) / scale,
    (square.top - box.top - top) / scale,
    size,
    size,
    0,
    0,
    canvas.width,
    canvas.height,
  );
  return canvas;
};

const loop = () => {
  timer = window.setTimeout(async () => {
    if (!running.value || !video.value || !detector) {
      return;
    }
    if (auto.value) {
      const code = await read();
      if (code) {
        hit(code);
      }
    }
    loop();
  }, 150);
};

const read = async (): Promise<string | null> => {
  try {
    const image = insideFrame();
    const [result] = image ? await detector!.detect(image) : [];
    return result?.rawValue || null;
  } catch {
    // a frame that can not be read
    return null;
  }
};

// manual mode: a few frames in a row, then give up with a notice
let noticeTimer = 0;
const scanOnce = async () => {
  if (searching.value || !detector) {
    return;
  }
  searching.value = true;
  notice.value = null;
  let code: string | null = null;
  for (let attempt = 0; attempt < 8 && !code && running.value; attempt++) {
    code = await read();
    if (!code) {
      await new Promise((resolve) => setTimeout(resolve, 120));
    }
  }
  searching.value = false;
  if (code) {
    hit(code, true);
  } else if (running.value) {
    notice.value = "Kein Code im Quadrat";
    clearTimeout(noticeTimer);
    noticeTimer = window.setTimeout(() => (notice.value = null), 2000);
  }
};

// asked: a tap in manual mode, which may read the same code again on purpose
const hit = (code: string, asked = false) => {
  const now = Date.now();
  if (!asked && code === lastCode && now - lastAt < REPEAT_MS) {
    lastAt = now;
    return;
  }
  lastCode = code;
  lastAt = now;
  beep();
  navigator.vibrate?.(80);
  emit("scan", code);
};

// short beep without an audio file
let audio: AudioContext | null = null;
const beep = () => {
  try {
    audio ??= new AudioContext();
    const oscillator = audio.createOscillator();
    const gain = audio.createGain();
    oscillator.frequency.value = 1200;
    gain.gain.value = 0.1;
    oscillator.connect(gain).connect(audio.destination);
    oscillator.start();
    oscillator.stop(audio.currentTime + 0.08);
  } catch {
    // no sound
  }
};

const toggleTorch = async () => {
  const track = stream?.getVideoTracks()[0];
  if (!track) {
    return;
  }
  torch.value = !torch.value;
  await track
    .applyConstraints({
      advanced: [{ torch: torch.value } as MediaTrackConstraintSet],
    })
    .catch(() => (torch.value = false));
};

const nextCamera = async () => {
  cameraIndex.value = (cameraIndex.value + 1) % cameras.value.length;
  stopStream();
  await start(cameras.value[cameraIndex.value].deviceId);
};

const stopStream = () => {
  clearTimeout(timer);
  stream?.getTracks().forEach((track) => track.stop());
  stream = null;
  running.value = false;
};

const stop = () => {
  stopStream();
  emit("stopped");
};

onMounted(() => {
  if (props.autostart) {
    start();
  }
});
onBeforeUnmount(stopStream);

defineExpose({ start, stop });
</script>
