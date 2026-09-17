<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '../components/AppButton.vue'
import AppIcon from '../components/AppIcon.vue'
import { useAuthStore } from '../stores/auth'
import { ApiError } from '../services/apiClient'

const props = defineProps<{
  audience: 'admin' | 'employee'
}>()

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
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

    const isAdmin = auth.roles.some((role) => ['ADMIN', 'SUPER_ADMIN'].includes(role))
    const expectedAdmin = props.audience === 'admin'

    if (isAdmin !== expectedAdmin) {
      await auth.logout()
      errorMessage.value = expectedAdmin
        ? 'This account belongs to the employee app. Use the employee sign-in.'
        : 'This account belongs to the admin console. Use the admin sign-in.'
      return
    }

    await router.push(expectedAdmin ? { name: 'dashboard' } : { name: 'employee-app' })
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
        <p class="visual-kicker">{{ props.audience === 'admin' ? 'ADMIN CONSOLE' : 'EMPLOYEE APP' }}</p>
        <h1>{{ props.audience === 'admin' ? 'Run the day,' : 'Your day,' }}<br /><strong>{{ props.audience === 'admin' ? 'with clarity.' : 'on record.' }}</strong></h1>
        <p class="visual-copy">{{ props.audience === 'admin' ? 'A focused workspace for attendance operations and workforce insights.' : 'A clear, reliable way to record your attendance wherever you work.' }}</p>
      </div>
    </section>

    <section class="login-panel">
      <div class="login-heading">
        <p class="eyebrow">{{ props.audience === 'admin' ? 'ADMIN ACCESS' : 'EMPLOYEE ACCESS' }}</p>
        <h2>{{ props.audience === 'admin' ? 'Sign in to admin console' : 'Sign in to employee app' }}</h2>
        <p class="login-intro">Use your company credentials to continue.</p>
      </div>

      <form novalidate @submit.prevent="submit">
        <label class="field-label">
          <span>Email address</span>
          <span class="field-control">
            <AppIcon name="Mail" class-name="field-icon" :size="16" :stroke-width="2" aria-hidden="true" />
            <input v-model="email" type="email" name="email" autocomplete="username" placeholder="you@mito.co.id" />
          </span>
        </label>
        <p v-if="fieldErrors.email" class="error">{{ fieldErrors.email[0] }}</p>

        <label class="field-label">
          <span>Password</span>
          <span class="field-control">
            <AppIcon name="LockKeyhole" class-name="field-icon" :size="16" :stroke-width="2" aria-hidden="true" />
            <input
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              name="password"
              autocomplete="current-password"
              placeholder="Enter your password"
            />
            <button
              type="button"
              class="password-toggle"
              :aria-label="showPassword ? 'Hide password' : 'Show password'"
              @click="showPassword = !showPassword"
            >
              <AppIcon v-if="!showPassword" name="Eye" :size="16" :stroke-width="2" />
              <AppIcon v-else name="EyeOff" :size="16" :stroke-width="2" />
            </button>
          </span>
        </label>
        <p v-if="fieldErrors.password" class="error">{{ fieldErrors.password[0] }}</p>

        <p v-if="errorMessage" class="error error-banner" role="alert">{{ errorMessage }}</p>

        <AppButton type="submit" :disabled="auth.isLoading" icon="ArrowRight">
          {{ auth.isLoading ? 'Signing in…' : 'Sign in' }}
        </AppButton>
      </form>

      <RouterLink
        v-if="props.audience === 'admin'"
        class="portal-switch"
        :to="{ name: 'login.employee' }"
      >
        Need employee access?
        <AppIcon name="ArrowRight" :size="16" :stroke-width="2.2" aria-hidden="true" />
      </RouterLink>
      <p class="login-note"><AppIcon name="ShieldCheck" class-name="secure-mark" :size="14" :stroke-width="2.5" /> Your connection is protected and secure.</p>
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
  width: min(100%, 32rem);
  margin: auto;
  padding: clamp(1.5rem, 4vw, 3rem);
  animation: login-rise 0.7s 0.1s ease both;
}

.login-heading {
  margin-bottom: 1.75rem;
}

.portal-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  margin-bottom: 1.25rem;
  padding: 0.38rem 0.6rem;
  border: 1px solid var(--accent-border);
  border-radius: 999px;
  background: var(--accent-bg);
  color: var(--accent);
  font-size: 0.7rem;
  font-weight: 700;
}

.portal-badge-dot {
  width: 0.42rem;
  height: 0.42rem;
  border-radius: 50%;
  background: var(--accent);
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

.field-label {
  gap: 0.45rem;
}

.field-control {
  position: relative;
  display: flex;
  align-items: center;
}

.field-icon {
  position: absolute;
  left: 0.9rem;
  z-index: 1;
  color: #a1a1a8;
  font-size: 0.85rem;
  font-weight: 700;
  pointer-events: none;
}

input {
  box-sizing: border-box;
  width: 100%;
  padding: 0.9rem 2.5rem;
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

.password-toggle {
  position: absolute;
  right: 0.65rem;
  width: auto;
  margin: 0;
  padding: 0.25rem;
  border: 0;
  background: transparent;
  color: var(--accent);
  font-size: 0.68rem;
  font-weight: 700;
  box-shadow: none;
  transform: none;
}

.password-toggle:hover:not(:disabled) {
  background: transparent;
  box-shadow: none;
  color: #c9151c;
  transform: none;
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

.portal-switch {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 1rem;
  padding: 0.75rem 0;
  border-bottom: 1px solid var(--border);
  color: var(--text);
  font-size: 0.78rem;
  font-weight: 600;
  text-decoration: none;
}

.portal-switch span {
  color: var(--accent);
  font-size: 1rem;
}

.portal-switch:hover {
  color: var(--accent);
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
