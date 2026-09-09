<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { ApiError } from '../services/apiClient'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')
const errorMessage = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

async function submit(): Promise<void> {
  errorMessage.value = ''
  fieldErrors.value = {}

  if (!email.value || !password.value) {
    errorMessage.value = 'Email and password are required.'
    return
  }

  try {
    await auth.login(email.value, password.value)

    const redirect = route.query.redirect
    await router.push(typeof redirect === 'string' ? redirect : { name: 'home' })
  } catch (error) {
    if (error instanceof ApiError) {
      errorMessage.value = error.message
      fieldErrors.value = error.errors
    } else {
      errorMessage.value = 'Unable to sign in. Please try again.'
    }
  }
}
</script>

<template>
  <main class="login">
    <h1>Attendance MITO Group</h1>
    <h2>Sign in</h2>

    <form novalidate @submit.prevent="submit">
      <label>
        Email
        <input v-model="email" type="email" name="email" autocomplete="username" />
      </label>
      <p v-if="fieldErrors.email" class="error">{{ fieldErrors.email[0] }}</p>

      <label>
        Password
        <input
          v-model="password"
          type="password"
          name="password"
          autocomplete="current-password"
        />
      </label>
      <p v-if="fieldErrors.password" class="error">{{ fieldErrors.password[0] }}</p>

      <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>

      <button type="submit" :disabled="auth.isLoading">
        {{ auth.isLoading ? 'Signing in…' : 'Sign in' }}
      </button>
    </form>
  </main>
</template>

<style scoped>
.login {
  max-width: 22rem;
  margin: 3rem auto;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

input {
  padding: 0.5rem;
}

button {
  padding: 0.5rem 1rem;
  cursor: pointer;
}

button:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.error {
  color: #b91c1c;
  margin: 0;
}
</style>
