<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '../components/AppButton.vue'
import { useAuthStore } from '../stores/auth'
import { usePermission } from '../features/auth/composables/usePermission'
import { apiFetch, ApiError } from '../services/apiClient'
import type { ApiResponse } from '../types/api'

const auth = useAuthStore()
const router = useRouter()
const { can, canAny } = usePermission()

const apiStatus = ref('Loading...')
const rbacStatus = ref('')

onMounted(async () => {
  try {
    const response = await apiFetch<ApiResponse<{ status: string; service: string }>>(
      '/health',
    )
    apiStatus.value = `${response.data.service}: ${response.data.status}`
  } catch {
    apiStatus.value = 'API connection failed'
  }

  // Demonstrates a permission-protected API call through the real backend.
  if (can('dashboard.view')) {
    try {
      await apiFetch('/rbac/demo')
      rbacStatus.value = 'RBAC demo: allowed'
    } catch (error) {
      rbacStatus.value =
        error instanceof ApiError && error.status === 403
          ? 'RBAC demo: denied (403)'
          : 'RBAC demo: failed'
    }
  }
})

async function logout(): Promise<void> {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <main class="home-page">
    <header class="home-header">
      <div class="header-left">
        <div class="brand-lockup">
          <img class="brand-logo" src="/images/mito.png" alt="MITO electronic" />
          <div>
            <p class="brand-eyebrow">MITO GROUP</p>
            <h1>Attendance</h1>
          </div>
        </div>
        <RouterLink v-if="can('dashboard.view')" to="/dashboard" class="header-link">
          Dashboard
        </RouterLink>
        <RouterLink to="/attendance" class="header-link">
          Attendance
        </RouterLink>
        <RouterLink v-if="canAny(['attendance.view', 'leave.view', 'overtime.view', 'penalty.view', 'monthly_recap.view'])" to="/dashboard/reports" class="header-link">
          Reports
        </RouterLink>
      </div>
      <div v-if="auth.isAuthenticated" class="user-menu">
        <span>{{ auth.user?.name }} ({{ auth.user?.email }})</span>
        <span v-if="auth.roles.length" class="role-label">{{ auth.roles.join(', ') }}</span>
        <AppButton type="button" variant="secondary" :disabled="auth.isLoading" @click="logout">
          Logout
        </AppButton>
      </div>
    </header>

    <section class="home-status" aria-label="System status">
      <div class="status-line">
        <span class="status-dot" aria-hidden="true" />
        <span>{{ apiStatus }}</span>
      </div>
      <div v-if="rbacStatus" class="status-line status-secondary">
        <span>{{ rbacStatus }}</span>
      </div>
    </section>
  </main>
</template>

<style scoped>
.home-page {
  min-height: 100svh;
  padding: 0 clamp(1rem, 3vw, 2rem);
  text-align: left;
}

.home-header {
  max-width: 1126px;
  margin: 0 auto;
  padding: 1.25rem 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1.5rem;
  border-bottom: 1px solid var(--border);
}

.header-left {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  flex-wrap: wrap;
}

.brand-lockup {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  margin-right: 0.5rem;
}

.brand-logo {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 6px;
  object-fit: cover;
}

.brand-eyebrow {
  margin: 0;
  color: var(--accent);
  font-size: 0.58rem;
  font-weight: 700;
  letter-spacing: 0.13em;
}

.brand-lockup h1 {
  margin: 0.05rem 0 0;
  font-size: 1.15rem;
  letter-spacing: 0;
}

.header-link {
  color: var(--text-h);
  text-decoration: none;
  font-size: 0.95rem;
  font-weight: 600;
}

.header-link:hover {
  color: var(--accent);
}

.user-menu {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
  justify-content: flex-end;
  color: var(--text);
  font-size: 0.85rem;
}

.role-label {
  padding: 0.25rem 0.5rem;
  border-radius: 999px;
  background: var(--accent-bg);
  color: var(--accent);
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
}

.user-menu button {
  margin: 0;
  padding: 0.55rem 0.8rem;
  border: 1px solid var(--accent);
  border-radius: 6px;
  background: #fff;
  color: var(--accent);
  font-weight: 700;
}

.user-menu button:hover:not(:disabled) {
  background: var(--accent-bg);
}

.home-status {
  max-width: 1126px;
  margin: 2rem auto;
  padding: 1rem 1.1rem;
  border: 1px solid var(--border);
  border-left: 3px solid var(--accent);
  border-radius: 6px;
  box-shadow: var(--shadow);
}

.status-line {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  color: var(--text-h);
  font-weight: 600;
}

.status-secondary {
  margin-top: 0.35rem;
  color: var(--text);
  font-size: 0.88rem;
  font-weight: 400;
}

.status-dot {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  background: var(--accent);
}

@media (max-width: 720px) {
  .home-header {
    align-items: flex-start;
    flex-direction: column;
  }

  .header-left {
    gap: 1rem;
  }

  .user-menu {
    justify-content: flex-start;
  }
}
</style>
