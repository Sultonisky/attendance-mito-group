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
    <section class="login-visual" aria-label="MITO Attendance">

      <div class="visual-content">
        <div class="logo-frame">
          <img class="brand-logo" src="/images/mito.png" alt="MITO electronic" />
        </div>
        <p class="visual-kicker">ATTENDANCE MANAGEMENT</p>
        <h1>Every day,<br /><strong>on record.</strong></h1>
        <p class="visual-copy">A clear, reliable workspace for your attendance and daily work activity.</p>
      </div>
    </section>

    <section class="login-panel">
      <div class="login-heading">
        <p class="eyebrow">WELCOME BACK</p>
        <h2>Sign in to your account</h2>
        <p class="login-intro">Use your company credentials to continue.</p>
      </div>

      <form novalidate @submit.prevent="submit">
        <label>
          Email address
          <input v-model="email" type="email" name="email" autocomplete="username" placeholder="you@mito.co.id" />
        </label>
        <p v-if="fieldErrors.email" class="error">{{ fieldErrors.email[0] }}</p>

        <label>
          Password
          <input
            v-model="password"
            type="password"
            name="password"
            autocomplete="current-password"
            placeholder="Enter your password"
          />
        </label>
        <p v-if="fieldErrors.password" class="error">{{ fieldErrors.password[0] }}</p>

        <p v-if="errorMessage" class="error error-banner" role="alert">{{ errorMessage }}</p>

        <button type="submit" :disabled="auth.isLoading">
          <span>{{ auth.isLoading ? 'Signing in…' : 'Sign in' }}</span>
          <span aria-hidden="true">→</span>
        </button>
      </form>

      <p class="login-note"><span class="secure-mark">✓</span> Your connection is protected and secure.</p>
    </section>
  </main>
</template>

<style scoped>
.login {
  box-sizing: border-box;
  width: 100%;
  min-height: 100svh;
  margin: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  background: #f8f8f8;
}

.login-visual {
  position: relative;
  box-sizing: border-box;
  min-height: 100svh;
  padding: clamp(2rem, 5vw, 5rem);
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  overflow: hidden;
  background: var(--accent);
  color: #fff;
}

.login-visual::after {
  content: '';
  position: absolute;
  right: -14rem;
  bottom: -15rem;
  width: 32rem;
  height: 32rem;
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: 50%;
  box-shadow: 0 0 0 3rem rgba(255, 255, 255, 0.04), 0 0 0 6rem rgba(255, 255, 255, 0.04);
}

.visual-topline,
.visual-footer {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 0.6rem;
  font-size: 0.66rem;
  font-weight: 700;
  letter-spacing: 0.14em;
}

.visual-dot {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 50%;
  background: #fff;
}

.visual-content {
  position: relative;
  z-index: 1;
  width: min(100%, 30rem);
  margin-inline: auto;
  text-align: center;
  transform: translateY(-2vh);
  animation: login-rise 0.7s ease both;
}

.logo-frame {
  display: inline-flex;
  padding: 0.45rem;
  margin-bottom: 2rem;
  border-radius: 13px;
  background: #fff;
  box-shadow: 0 12px 30px rgba(90, 0, 5, 0.2);
}

.brand-logo {
  display: block;
  width: clamp(104px, 10vw, 146px);
  height: clamp(104px, 10vw, 146px);
  border-radius: 9px;
  object-fit: cover;
}

.visual-kicker,
.eyebrow {
  margin: 0;
  color: var(--accent);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.13em;
}

.visual-kicker {
  color: rgba(255, 255, 255, 0.78);
}

.visual-content h1 {
  margin: 0.8rem 0 1.1rem;
  color: #fff;
  font-size: clamp(2.8rem, 5vw, 5.2rem);
  line-height: 0.98;
  letter-spacing: -0.06em;
}

.visual-content h1 strong {
  font-weight: 700;
}

.visual-copy {
  max-width: 23rem;
  margin-inline: auto;
  color: rgba(255, 255, 255, 0.78);
  font-size: 1rem;
  line-height: 1.6;
}

.visual-footer {
  color: rgba(255, 255, 255, 0.68);
}

.visual-footer span {
  color: #fff;
}

.login-panel {
  box-sizing: border-box;
  width: min(100%, 31rem);
  margin: auto;
  padding: 2rem;
  animation: login-rise 0.7s 0.1s ease both;
}

.login-heading {
  margin-bottom: 2rem;
}

.login-heading h2 {
  margin: 0.55rem 0 0.45rem;
  color: var(--text-h);
  font-size: clamp(1.7rem, 3vw, 2.25rem);
  font-weight: 700;
  letter-spacing: -0.04em;
}

.login-intro {
  color: var(--text);
  line-height: 1.55;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  color: var(--text-h);
  font-size: 0.78rem;
  font-weight: 600;
}

input {
  box-sizing: border-box;
  width: 100%;
  padding: 0.9rem 1rem;
  border: 1px solid #dedee2;
  border-radius: 7px;
  color: var(--text-h);
  background: var(--surface);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

input::placeholder {
  color: #aaaab1;
}

input:focus {
  border-color: var(--accent);
  outline: none;
  box-shadow: 0 0 0 4px rgba(235, 28, 36, 0.1);
}

button {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  margin-top: 1.35rem;
  padding: 0.9rem 1rem 0.9rem 1.1rem;
  border: 0;
  border-radius: 7px;
  background: var(--accent);
  color: #fff;
  font-weight: 700;
  transition: background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
}

button:hover:not(:disabled) {
  background: #c9151c;
  transform: translateY(-1px);
  box-shadow: 0 8px 18px rgba(235, 28, 36, 0.2);
}

button:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.error {
  color: #b91c1c;
  margin: 0;
}

.error-banner {
  padding: 0.75rem;
  border-radius: 6px;
  background: #fff1f2;
  font-size: 0.85rem;
}

.login-note {
  margin-top: 1.25rem;
  color: #85858d;
  font-size: 0.78rem;
  text-align: center;
}

.secure-mark {
  display: inline-grid;
  width: 1rem;
  height: 1rem;
  margin-right: 0.25rem;
  place-items: center;
  border-radius: 50%;
  background: #e8f7ee;
  color: #25834a;
  font-size: 0.65rem;
  font-weight: 700;
}

@keyframes login-rise {
  from {
    opacity: 0;
    transform: translateY(12px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (max-width: 760px) {
  .login {
    display: block;
    background: #f8f8f8;
  }

  .login-visual {
    min-height: 300px;
    padding: 1.5rem;
  }

  .visual-content {
    margin-top: 0;
    transform: none;
  }

  .visual-content h1 {
    font-size: 2.6rem;
  }

  .visual-copy,
  .visual-footer {
    display: none;
  }

  .logo-frame {
    margin-bottom: 1rem;
  }

  .brand-logo {
    width: 78px;
    height: 78px;
  }

  .login-panel {
    width: 100%;
    padding: 2rem 1.25rem 2.5rem;
  }
}
</style>
