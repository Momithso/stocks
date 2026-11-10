<template>
  <div class="relative overflow-hidden rounded-lg bg-black">
    <video
      v-show="running"
      ref="video"
      class="h-full w-full object-cover"
      playsinline
      muted
    />

    <!-- viewfinder -->
    <div
      v-if="running"
      class="pointer-events-none absolute inset-0 flex items-center justify-center"
    >
      <div
        class="aspect-square h-3/5 max-h-80 rounded-xl border-4 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)]"
      />
    </div>

    <div v-if="running" class="absolute right-2 top-2 flex gap-2">
      <button
        v-if="torchAvailable"
        type="button"
        :class="[
          'flex h-10 w-10 items-center justify-center rounded-full',
          torch ? 'bg-yellow-400 text-black' : 'bg-black/50 text-white',
        ]"
        aria-label="Taschenlampe"
        @click="toggleTorch"
      >
        <FontAwesomeIcon :icon="['fas', 'bolt']" />
      </button>
      <button
        v-if="cameras.length > 1"
        type="button"
        class="flex h-10 w-10 items-center justify-center rounded-full bg-black/50 text-white"
        aria-label="Kamera wechseln"
        @click="nextCamera"
      >
        <FontAwesomeIcon :icon="['fas', 'camera-rotate']" />
      </button>
      <button
        type="button"
        class="flex h-10 w-10 items-center justify-center rounded-full bg-black/50 text-white"
        aria-label="Kamera ausschalten"
        @click="stop"
      >
        <FontAwesomeIcon :icon="['fas', 'xmark']" />
      </button>
    </div>

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
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";

/**
 * Camera scan of qr codes and data matrix codes with barcode-detector (ZXing). The WebAssembly
 * file is part of the build, nothing is loaded from other servers. The same code only fires again
 * after a pause; every hit beeps and vibrates.
 */
const props = withDefaults(defineProps<{ autostart?: boolean }>(), {
  autostart: false,
});
const emit = defineEmits<{ scan: [code: string]; started: []; stopped: [] }>();

const video = ref<HTMLVideoElement | null>(null);
const running = ref(false);
const starting = ref(false);
const error = ref<string | null>(null);
const cameras = ref<MediaDeviceInfo[]>([]);
const cameraIndex = ref(0);
const torchAvailable = ref(false);
const torch = ref(false);

let stream: MediaStream | null = null;
let detector: {
  detect: (source: HTMLVideoElement) => Promise<{ rawValue: string }[]>;
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

const loop = () => {
  timer = window.setTimeout(async () => {
    if (!running.value || !video.value || !detector) {
      return;
    }
    try {
      const [result] = await detector.detect(video.value);
      if (result?.rawValue) {
        hit(result.rawValue);
      }
    } catch {
      // a frame that can not be read
    }
    loop();
  }, 150);
};

const hit = (code: string) => {
  const now = Date.now();
  if (code === lastCode && now - lastAt < REPEAT_MS) {
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
