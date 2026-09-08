<script setup lang="ts">
import { onMounted, ref } from 'vue'

const apiStatus = ref('Loading...')

onMounted(async () => {
  try {
    const result = await fetch('/health')
    if (!result.ok) {
      throw new Error(`Request failed with status ${result.status}`)
    }

    const response = (await result.json()) as {
      status: string
      service: string
    }

    apiStatus.value = `${response.service}: ${response.status}`
  } catch {
    apiStatus.value = 'API connection failed'
  }
})
</script>

<template>
  <main>
    <h1>Attendance System</h1>
    <p>{{ apiStatus }}</p>
  </main>
</template>
