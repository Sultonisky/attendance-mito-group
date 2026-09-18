import './style.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createHead } from '@unhead/vue/client'
import ui from '@nuxt/ui/vue-plugin'

import App from './App.vue'
import router from './router'

const app = createApp(App)
const head = createHead()

// Suppress the known upstream dev-only warning:
// reka-ui SelectItemIndicator passes aria-hidden="true" (string) through
// Primitive Slot to @iconify/vue Icon whose `ariaHidden` prop is Boolean.
// The SVG renders correctly with aria-hidden="true" either way; the warn is
// noise from @nuxt/ui → reka-ui → @iconify/vue prop mismatch.
// See: reka-ui SelectItemIndicator.vue `"aria-hidden": "true"` vs
// @iconify/vue `ariaHidden: { type: Boolean }`.
// Narrow match so real aria/Select warnings still surface.
app.config.warnHandler = (msg, _instance, trace) => {
  if (
    typeof msg === 'string'
    && msg.includes('Invalid prop')
    && msg.includes('"ariaHidden"')
    && msg.includes('got String')
  ) {
    return
  }
  console.warn(`[Vue warn]: ${msg}${trace ? `\n${trace}` : ''}`)
}

app.use(createPinia())
app.use(router)
app.use(head)
app.use(ui)

app.mount('#app')
