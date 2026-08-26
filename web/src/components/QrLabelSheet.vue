<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  items: { type: Array, required: true },
})

const images = ref({})

function print() {
  window.print()
}

watch(
  () => props.items,
  async (items) => {
    const QRCode = (await import('qrcode')).default
    const entries = await Promise.all(
      items.map(async (item) => [
        item.id,
        await QRCode.toDataURL(`ICT:${item.id}`, {
          margin: 1,
          width: 220,
          errorCorrectionLevel: 'M',
        }),
      ]),
    )
    images.value = Object.fromEntries(entries)
  },
  { immediate: true },
)
</script>

<template>
  <div>
    <div style="display: flex; justify-content: flex-end; margin-bottom: 0.75rem">
      <Button label="Print labels" icon="pi pi-print" size="small" @click="print" />
    </div>

    <div id="print-region" class="label-sheet">
      <figure v-for="item in items" :key="item.id" class="qr-label">
        <img
          v-if="images[item.id]"
          :src="images[item.id]"
          :alt="`QR code for ${item.assetTag}`"
        />
        <div v-else class="qr-placeholder" />
        <figcaption>
          <strong class="mono">{{ item.assetTag }}</strong>
          <span v-if="item.propertyNumber" class="mono">{{ item.propertyNumber }}</span>
          <span>{{ item.modelName }}</span>
          <span v-if="item.serialNumber" class="mono serial">SN {{ item.serialNumber }}</span>
          <span v-if="item.parIssuedTo" class="serial">{{ item.parIssuedTo }}</span>
        </figcaption>
      </figure>
    </div>
  </div>
</template>

<style scoped>
.qr-placeholder {
  width: 8rem;
  height: 8rem;
  border-radius: 0.35rem;
  background: #e5e7eb;
}

figcaption {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  font-size: 0.72rem;
}

figcaption strong {
  font-size: 0.82rem;
}

.serial {
  font-size: 0.65rem;
  opacity: 0.7;
}
</style>
