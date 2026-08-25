<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({
  autoStart: { type: Boolean, default: true },
})
const emit = defineEmits(['detected'])

const READER_ID = 'ict-code-reader'

const cameraOn = ref(props.autoStart)
const cameraError = ref(null)
const manual = ref('')

let scanner = null
let running = false
let disposed = false

/**
 * html5-qrcode's `stop()` throws *synchronously* when the scanner was never
 * started — exactly what happens when camera permission is denied. Guard on
 * `running` and swallow, or the throw escapes and takes the view down.
 */
async function teardown() {
  const instance = scanner
  scanner = null
  if (!instance) return

  if (running) {
    running = false
    try {
      await instance.stop()
    } catch {
      /* already stopped */
    }
  }
  try {
    instance.clear()
  } catch {
    /* nothing rendered yet */
  }
}

async function startCamera() {
  disposed = false
  try {
    const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import('html5-qrcode')
    if (disposed) return

    scanner = new Html5Qrcode(READER_ID, {
      verbose: false,
      formatsToSupport: [
        Html5QrcodeSupportedFormats.QR_CODE,
        Html5QrcodeSupportedFormats.CODE_128,
        Html5QrcodeSupportedFormats.CODE_39,
        Html5QrcodeSupportedFormats.EAN_13,
        Html5QrcodeSupportedFormats.DATA_MATRIX,
      ],
    })

    await scanner.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: { width: 240, height: 240 } },
      (decoded) => emit('detected', decoded.trim()),
      () => {
        /* per-frame decode misses are expected */
      },
    )
    running = true
    if (disposed) await teardown()
  } catch (err) {
    await teardown()
    if (disposed) return
    cameraError.value =
      err?.message ?? 'Camera unavailable. Use manual entry or a USB scanner.'
    cameraOn.value = false
  }
}

watch(
  cameraOn,
  (on) => {
    if (on) {
      // Wait a tick so the target div exists before html5-qrcode looks for it.
      requestAnimationFrame(() => startCamera())
    } else {
      disposed = true
      teardown()
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  disposed = true
  teardown()
})

function submitManual() {
  const code = manual.value.trim()
  if (!code) return
  emit('detected', code)
  manual.value = ''
}
</script>

<template>
  <div class="scanner">
    <div class="scanner-frame">
      <div v-show="cameraOn" :id="READER_ID" style="width: 100%" />
      <div v-if="!cameraOn" class="scanner-off">
        <i class="pi pi-qrcode" />
        <p>{{ cameraError ?? 'Camera is off.' }}</p>
        <Button
          label="Start camera"
          icon="pi pi-camera"
          size="small"
          severity="secondary"
          @click="
            () => {
              cameraError = null
              cameraOn = true
            }
          "
        />
      </div>
    </div>

    <Button
      v-if="cameraOn"
      label="Stop camera"
      icon="pi pi-times"
      size="small"
      severity="secondary"
      text
      @click="cameraOn = false"
    />

    <div class="field">
      <label for="manual-code">
        <i class="pi pi-keyboard" style="margin-right: 0.35rem" />
        Asset tag, serial, or scanned code
      </label>
      <div class="scanner-input">
        <InputText
          id="manual-code"
          v-model="manual"
          placeholder="ICT-LT-0001"
          autofocus
          class="mono"
          @keydown.enter.prevent="submitManual"
        />
        <Button label="Look up" :disabled="!manual.trim()" @click="submitManual" />
      </div>
      <span class="hint">
        USB barcode scanners type the code and press Enter — that works here too.
      </span>
    </div>
  </div>
</template>

<style scoped>
.scanner {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.scanner-off {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 1.5rem 1rem;
  color: rgba(255, 255, 255, 0.75);
  text-align: center;
}

.scanner-off i {
  font-size: 1.6rem;
  opacity: 0.6;
}

.scanner-off p {
  margin: 0;
  font-size: 0.8rem;
}

.scanner-input {
  display: flex;
  gap: 0.5rem;
}

.scanner-input :deep(input) {
  flex: 1;
}
</style>
