<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
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
  <main>
    <header>
      <div class="header-left">
        <h1>Attendance System</h1>
        <RouterLink v-if="can('dashboard.view')" to="/dashboard" class="header-link">
          Dashboard
        </RouterLink>
        <RouterLink v-if="canAny(['attendance.view', 'leave.view', 'overtime.view', 'penalty.view', 'monthly_recap.view'])" to="/reports" class="header-link">
          Reports
        </RouterLink>
      </div>
      <div v-if="auth.isAuthenticated">
        <span>{{ auth.user?.name }} ({{ auth.user?.email }})</span>
        <span v-if="auth.roles.length"> — roles: {{ auth.roles.join(', ') }}</span>
        <button type="button" :disabled="auth.isLoading" @click="logout">
          Logout
        </button>
      </div>
    </header>

    <p>{{ apiStatus }}</p>
    <p v-if="rbacStatus">{{ rbacStatus }}</p>
  </main>
</template>

<style scoped>
.header-left {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}

.header-link {
  color: var(--text);
  text-decoration: none;
  font-size: 0.95rem;
}

.header-link:hover {
  color: var(--text-h);
}
</style>
