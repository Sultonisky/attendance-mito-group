<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, resolveComponent, watch } from 'vue'
import { watchDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../composables/useReportPage'
import { useDataTableSort } from '../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../composables/useDataTableDisplay'
import { usePermission } from '../../features/auth/composables/usePermission'
import { useAppToast } from '../../composables/useAppToast'
import {
  fetchPermissions,
  fetchPermissionUsers,
  createPermission,
  updatePermission,
  deletePermission,
  assignPermissionUser,
  revokePermissionUser,
  fetchRoles,
  updateRolePermissions,
  type PermissionRow,
  type PermissionUser,
  type RoleRow,
} from '../../services/permissionApi'
import { fetchUsers, fetchUserPermissions, updateUserPermissions } from '../../services/userApi'
import PermissionChecklist, { type PermissionCatalogItem } from '../../components/PermissionChecklist.vue'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import { createSortableHeader, createTruncatedText } from '../../utils/dataTable'

const { loading, error, filterError, meta, clearErrors, handleApiError, applyMeta, goToPage } = useReportPage()
const { can } = usePermission()
const toast = useAppToast()

// ── Table data ────────────────────────────────────────────────────────────────
const data = ref<PermissionRow[]>([])
const columnVisibility = ref<VisibilityState>()
const searchInput = ref('')

// ── Filters ───────────────────────────────────────────────────────────────────
const filters = reactive({
  search: '',
  per_page: 25,
  sort: 'name',
  direction: 'asc' as 'asc' | 'desc',
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const hideableColumns = [
  { id: 'name',            label: 'Name'            },
  { id: 'description',     label: 'Description'     },
  { id: 'created_at',      label: 'Created'         },
  { id: 'actions',         label: 'Actions'         },
]
const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

// ── Modal — create/edit ───────────────────────────────────────────────────────
const showFormModal = ref(false)
const formMode      = ref<'create' | 'edit'>('create')
const formBusy      = ref(false)
const formError     = ref('')
const editingId     = ref<number | null>(null)

const form = reactive({
  name: '',
  description: '',
})

// ── Modal — delete confirm ────────────────────────────────────────────────────
const showDeleteModal = ref(false)
const deleteTarget    = ref<PermissionRow | null>(null)
const deleteBusy      = ref(false)
const deleteError     = ref('')

// ── Modal — assigned users ────────────────────────────────────────────────────
const showUsersModal = ref(false)
const usersTarget    = ref<PermissionRow | null>(null)
const usersBusy      = ref(false)
const usersError     = ref('')
const assignedUsers  = ref<PermissionUser[]>([])

// Picking a user needs the user list too, so both grants are required.
const canManageUsers = computed(() => can('permission.update') && can('user.view'))
const userSearch         = ref('')
const userOptions        = ref<{ label: string; description: string; value: number }[]>([])
const userOptionsLoading = ref(false)
const addUserId          = ref<number | undefined>(undefined)
const assignBusy         = ref(false)
const revokingId         = ref<number | null>(null)

function sameSet(a: string[], b: string[]): boolean {
  return [...a].sort().join('|') === [...b].sort().join('|')
}

// Shared by the role-template and user-permission editors.
const permissionCatalog = ref<PermissionCatalogItem[]>([])
const roles             = ref<RoleRow[]>([])

async function loadCatalogAndRoles(): Promise<void> {
  const [rolesRes, permsRes] = await Promise.all([
    fetchRoles(),
    fetchPermissions({ per_page: 100, sort: 'name', direction: 'asc' }),
  ])
  roles.value = rolesRes.data
  permissionCatalog.value = permsRes.data.map((p) => ({ name: p.name, description: p.description }))
}

// ── Modal — role templates ────────────────────────────────────────────────────
const showRolesModal   = ref(false)
const rolesBusy        = ref(false)
const rolesSaving      = ref(false)
const rolesError       = ref('')
const activeRoleId     = ref<number | null>(null)
const roleSelection    = ref<string[]>([])

const activeRole = computed(() => roles.value.find((r) => r.id === activeRoleId.value) ?? null)
const roleDirty  = computed(() => !!activeRole.value && !sameSet(activeRole.value.permissions, roleSelection.value))

// ── Modal — per-user permissions ──────────────────────────────────────────────
const showUserPermsModal = ref(false)
const userPermsBusy      = ref(false)
const userPermsSaving    = ref(false)
const userPermsError     = ref('')
const editUserId         = ref<number | undefined>(undefined)
const editUserSearch     = ref('')
const editUserOptions    = ref<{ label: string; description: string; value: number }[]>([])
const editUserLoading    = ref(false)
const editUserRole       = ref<string | null>(null)
const editUserBypass     = ref(false)
const editUserSaved      = ref<string[]>([])
const editUserSelection  = ref<string[]>([])

const editUserDirty    = computed(() => !sameSet(editUserSaved.value, editUserSelection.value))
const editUserTemplate = computed(() => roles.value.find((r) => r.name === editUserRole.value) ?? null)

// ── Columns ───────────────────────────────────────────────────────────────────
const columns = computed<TableColumn<PermissionRow>[]>(() => [
  {
    accessorKey: 'name',
    header: ({ column }) => createSortableHeader(column, 'Name'),
    cell: ({ row }) => createTruncatedText(row.original.name, 'text-sm font-mono'),
  },
  {
    accessorKey: 'description',
    header: ({ column }) => createSortableHeader(column, 'Description'),
    cell: ({ row }) => createTruncatedText(row.original.description, 'text-xs text-[var(--ui-text-muted)]'),
  },
  {
    id: 'assigned_users',
    header: () => h('div', { class: 'flex justify-center' }, 'Assigned Users'),
    cell: ({ row }) => {
      const count = row.original.users_count ?? 0
      return h('div', { class: 'flex items-center justify-center gap-2' }, [
        h('span', { class: 'text-xs text-[var(--ui-text-muted)]' }, `${count} user${count !== 1 ? 's' : ''}`),
        h(resolveComponent('UButton'), {
          size: 'xs',
          color: 'primary',
          variant: 'ghost',
          icon: 'i-lucide-users',
          'aria-label': 'View assigned users',
          onClick: () => openUsers(row.original),
        }),
      ])
    },
  },
  {
    accessorKey: 'created_at',
    header: ({ column }) => createSortableHeader(column, 'Created'),
    cell: ({ row }) => h('span', { class: 'text-xs text-[var(--ui-text-muted)]' }, row.original.created_at ?? '—'),
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const items = [
        can('permission.update') && {
          label: 'Edit',
          icon: 'i-lucide-pencil',
          onSelect: () => openEdit(row.original),
        },
        can('permission.delete') && {
          label: 'Delete',
          icon: 'i-lucide-trash-2',
          color: 'error' as const,
          onSelect: () => confirmDelete(row.original),
        },
      ].filter(Boolean)

      if (!items.length) return null

      return h('div', { class: 'flex justify-end' }, [
        h(resolveComponent('UDropdownMenu'), { items: [items] }, {
          default: () => h(resolveComponent('UButton'), {
            size: 'xs',
            color: 'neutral',
            variant: 'ghost',
            icon: 'i-lucide-more-horizontal',
            'aria-label': 'Row actions',
          }),
        }),
      ])
    },
  },
])

// ── Watches ───────────────────────────────────────────────────────────────────
watchDebounced(searchInput, (value) => {
  filters.search = value
  if (!ready.value) return
  meta.current_page = 1
  load()
}, { debounce: 400 })

watch(
  () => [filters.per_page] as const,
  () => {
    if (!ready.value) return
    meta.current_page = 1
    load()
  },
)

const ready = ref(false)

// ── Load ──────────────────────────────────────────────────────────────────────
async function load(): Promise<void> {
  loading.value = true
  clearErrors()
  try {
    const res = await fetchPermissions({
      ...filters,
      page: meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load permissions. Please try again.')
  } finally {
    loading.value = false
  }
}

// ── CRUD ──────────────────────────────────────────────────────────────────────
function openCreate(): void {
  formMode.value = 'create'
  editingId.value = null
  form.name = ''
  form.description = ''
  formError.value = ''
  showFormModal.value = true
}

function openEdit(permission: PermissionRow): void {
  formMode.value = 'edit'
  editingId.value = permission.id
  form.name = permission.name
  form.description = permission.description ?? ''
  formError.value = ''
  showFormModal.value = true
}

async function submitForm(): Promise<void> {
  if (!form.name.trim()) { formError.value = 'Permission name is required.'; return }

  formBusy.value = true
  formError.value = ''
  try {
    if (formMode.value === 'create') {
      await createPermission({ name: form.name.trim() })
      toast.success('Permission created')
    } else if (editingId.value !== null) {
      await updatePermission(editingId.value, { name: form.name.trim() })
      toast.success('Permission updated')
    }
    showFormModal.value = false
    await load()
  } catch (e: unknown) {
    formError.value = e instanceof Error ? e.message : 'Failed to save. Please try again.'
  } finally {
    formBusy.value = false
  }
}

function confirmDelete(permission: PermissionRow): void {
  deleteTarget.value = permission
  deleteError.value = ''
  showDeleteModal.value = true
}

async function executeDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleteBusy.value = true
  deleteError.value = ''
  try {
    const res = await deletePermission(deleteTarget.value.id)
    if (!res.success) {
      deleteError.value = 'Failed to delete permission.'
      toast.error('Delete failed', 'Failed to delete permission.')
      return
    }
    showDeleteModal.value = false
    deleteTarget.value = null
    toast.success('Permission deleted')
    await load()
  } catch (e: unknown) {
    deleteError.value = e instanceof Error ? e.message : 'Failed to delete permission.'
    toast.fromError(e, 'Unable to delete this permission.')
  } finally {
    deleteBusy.value = false
  }
}

function openUsers(permission: PermissionRow): void {
  usersTarget.value = permission
  usersError.value = ''
  assignedUsers.value = []
  addUserId.value = undefined
  userSearch.value = ''
  userOptions.value = []
  showUsersModal.value = true
  loadUsers(permission.id)
}

async function loadUsers(permissionId: number): Promise<void> {
  usersBusy.value = true
  usersError.value = ''
  try {
    const res = await fetchPermissionUsers(permissionId)
    assignedUsers.value = res.data
    if (canManageUsers.value) await loadUserOptions()
  } catch (e: unknown) {
    usersError.value = e instanceof Error ? e.message : 'Failed to load assigned users.'
  } finally {
    usersBusy.value = false
  }
}

async function loadUserOptions(): Promise<void> {
  userOptionsLoading.value = true
  try {
    const res = await fetchUsers({
      search: userSearch.value || undefined,
      status: 'active',
      per_page: 25,
      sort: 'name',
      direction: 'asc',
      page: 1,
    })
    const assigned = new Set(assignedUsers.value.map((u) => u.id))
    userOptions.value = res.data
      .filter((u) => !assigned.has(u.id))
      .map((u) => ({ label: u.name, description: u.email, value: u.id }))
  } catch (e: unknown) {
    toast.fromError(e, 'Unable to load users.')
  } finally {
    userOptionsLoading.value = false
  }
}

watchDebounced(userSearch, () => {
  if (showUsersModal.value && canManageUsers.value) loadUserOptions()
}, { debounce: 300 })

async function assignUser(): Promise<void> {
  if (!usersTarget.value || addUserId.value === undefined) return
  assignBusy.value = true
  try {
    await assignPermissionUser(usersTarget.value.id, addUserId.value)
    toast.success('Permission granted')
    addUserId.value = undefined
    await Promise.all([loadUsers(usersTarget.value.id), load()])
  } catch (e: unknown) {
    toast.fromError(e, 'Unable to grant this permission.')
  } finally {
    assignBusy.value = false
  }
}

async function revokeUser(user: PermissionUser): Promise<void> {
  if (!usersTarget.value) return
  revokingId.value = user.id
  try {
    await revokePermissionUser(usersTarget.value.id, user.id)
    toast.success('Permission revoked')
    await Promise.all([loadUsers(usersTarget.value.id), load()])
  } catch (e: unknown) {
    toast.fromError(e, 'Unable to revoke this permission.')
  } finally {
    revokingId.value = null
  }
}

// ── Role templates ────────────────────────────────────────────────────────────
async function openRoles(): Promise<void> {
  showRolesModal.value = true
  rolesBusy.value = true
  rolesError.value = ''
  try {
    await loadCatalogAndRoles()
    selectRole(roles.value.find((r) => r.editable)?.id ?? roles.value[0]?.id ?? null)
  } catch (e: unknown) {
    rolesError.value = e instanceof Error ? e.message : 'Failed to load roles.'
  } finally {
    rolesBusy.value = false
  }
}

function selectRole(id: number | null): void {
  activeRoleId.value = id
  roleSelection.value = [...(roles.value.find((r) => r.id === id)?.permissions ?? [])]
}

async function saveRolePermissions(): Promise<void> {
  if (!activeRole.value?.editable) return
  rolesSaving.value = true
  try {
    const res = await updateRolePermissions(activeRole.value.id, roleSelection.value)
    roles.value = roles.value.map((r) => (r.id === res.data.id ? res.data : r))
    selectRole(res.data.id)
    toast.success(`${res.data.name} template updated`)
  } catch (e: unknown) {
    toast.fromError(e, 'Unable to update the role template.')
  } finally {
    rolesSaving.value = false
  }
}

// ── Per-user permissions ──────────────────────────────────────────────────────
async function openUserPerms(): Promise<void> {
  showUserPermsModal.value = true
  userPermsBusy.value = true
  userPermsError.value = ''
  editUserId.value = undefined
  editUserSearch.value = ''
  editUserRole.value = null
  editUserBypass.value = false
  editUserSaved.value = []
  editUserSelection.value = []
  try {
    await Promise.all([loadCatalogAndRoles(), loadEditUserOptions()])
  } catch (e: unknown) {
    userPermsError.value = e instanceof Error ? e.message : 'Failed to load permissions.'
  } finally {
    userPermsBusy.value = false
  }
}

async function loadEditUserOptions(): Promise<void> {
  editUserLoading.value = true
  try {
    const res = await fetchUsers({
      search: editUserSearch.value || undefined,
      per_page: 25,
      sort: 'name',
      direction: 'asc',
      page: 1,
    })
    editUserOptions.value = res.data.map((u) => ({
      label: u.name,
      description: `${u.email} · ${u.role ?? 'no role'}`,
      value: u.id,
    }))
  } catch (e: unknown) {
    toast.fromError(e, 'Unable to load users.')
  } finally {
    editUserLoading.value = false
  }
}

watchDebounced(editUserSearch, () => {
  if (showUserPermsModal.value) loadEditUserOptions()
}, { debounce: 300 })

watch(editUserId, async (id) => {
  if (id === undefined) return
  userPermsBusy.value = true
  userPermsError.value = ''
  try {
    const res = await fetchUserPermissions(id)
    editUserRole.value = res.role
    editUserBypass.value = res.super_admin_bypass
    editUserSaved.value = [...res.data]
    editUserSelection.value = [...res.data]
  } catch (e: unknown) {
    userPermsError.value = e instanceof Error ? e.message : 'Failed to load user permissions.'
  } finally {
    userPermsBusy.value = false
  }
})

function applyTemplateToUser(): void {
  if (editUserTemplate.value) editUserSelection.value = [...editUserTemplate.value.permissions]
}

async function saveUserPermissions(): Promise<void> {
  if (editUserId.value === undefined || editUserBypass.value) return
  userPermsSaving.value = true
  try {
    const res = await updateUserPermissions(editUserId.value, editUserSelection.value)
    editUserSaved.value = [...res.data]
    editUserSelection.value = [...res.data]
    toast.success('User permissions updated')
    await load()
  } catch (e: unknown) {
    toast.fromError(e, 'Unable to update user permissions.')
  } finally {
    userPermsSaving.value = false
  }
}

onMounted(async () => {
  await load()
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="permission-management">
    <template #header>
      <UDashboardNavbar title="Permissions">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            v-if="can('user.view')"
            color="primary"
            variant="soft"
            size="sm"
            icon="i-lucide-user-cog"
            @click="openUserPerms"
          >
            User permissions
          </UButton>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-users-round"
            @click="openRoles"
          >
            Role templates
          </UButton>
          <UButton
            v-if="can('permission.create')"
            color="primary"
            size="sm"
            icon="i-lucide-plus"
            @click="openCreate"
          >
            Add permission
          </UButton>
          <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-filter-x" @click="searchInput = ''">
            Reset
          </UButton>
          <UButton color="neutral" variant="outline" size="sm" icon="i-lucide-refresh-cw" :loading="loading" @click="load">
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-4 sm:p-6 space-y-4">

        <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-triangle-alert" title="Failed to load" :description="error">
          <template #actions>
            <UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">Retry</UButton>
          </template>
        </UAlert>

        <template v-else>
          <UAlert
            v-if="filterError"
            color="warning"
            variant="subtle"
            icon="i-lucide-circle-alert"
            title="Filter tidak valid"
            :description="filterError"
          />

          <DataTableToolbar
            v-model:search="searchInput"
            search-placeholder="Search permission name…"
            :display-items="displayItems"
          />

          <DataTable
            v-model:sorting="sorting"
            v-model:column-visibility="columnVisibility"
            :data="data"
            :columns="columns"
            :loading="loading"
            :meta="meta"
            manual-sorting
            empty-icon="i-lucide-shield-x"
            empty-message="No permissions found."
            @update:page="goToPage($event, load)"
          />
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <!-- ── Create / Edit modal ───────────────────────────────────────────────── -->
  <UModal
    v-model:open="showFormModal"
    :title="formMode === 'create' ? 'Add permission' : 'Edit permission'"
  >
    <template #body>
      <div class="space-y-4">
        <UFormField label="Permission name" required>
          <UInput v-model="form.name" placeholder="module.action" class="w-full" />
        </UFormField>

        <UFormField label="Description">
          <UTextarea v-model="form.description" placeholder="Short explanation for admins..." class="w-full" rows="3" />
        </UFormField>

        <UAlert v-if="formError" color="error" variant="subtle" :description="formError" />
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="formBusy" @click="showFormModal = false">
          Cancel
        </UButton>
        <UButton color="primary" :loading="formBusy" @click="submitForm">
          {{ formMode === 'create' ? 'Add permission' : 'Save changes' }}
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- ── Delete confirm modal ───────────────────────────────────────────────── -->
  <UModal v-model:open="showDeleteModal" title="Delete permission">
    <template #body>
      <div class="space-y-3">
        <p class="text-sm text-muted">
          Are you sure you want to delete
          <strong class="text-highlighted">{{ deleteTarget?.name }}</strong>?
          This action cannot be undone.
        </p>
        <UAlert v-if="deleteError" color="error" variant="subtle" :description="deleteError" />
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="deleteBusy" @click="showDeleteModal = false">
          Cancel
        </UButton>
        <UButton color="error" :loading="deleteBusy" @click="executeDelete">
          Delete
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- ── Role templates modal ───────────────────────────────────────────────── -->
  <UModal v-model:open="showRolesModal" title="Role templates" :ui="{ content: 'sm:max-w-2xl' }">
    <template #body>
      <div class="space-y-4">
        <UAlert v-if="rolesError" color="error" variant="subtle" :description="rolesError" />

        <div v-else-if="rolesBusy" class="space-y-2">
          <div v-for="n in 6" :key="n" class="h-8 animate-pulse rounded bg-[var(--ui-bg-elevated)]" />
        </div>

        <template v-else>
          <div class="flex flex-wrap gap-2">
            <UButton
              v-for="role in roles"
              :key="role.id"
              size="sm"
              :color="role.id === activeRoleId ? 'primary' : 'neutral'"
              :variant="role.id === activeRoleId ? 'solid' : 'outline'"
              :disabled="rolesSaving"
              @click="selectRole(role.id)"
            >
              {{ role.name.replace('_', ' ') }}
              <span class="text-xs opacity-75">· {{ role.users_count }} user{{ role.users_count !== 1 ? 's' : '' }}</span>
            </UButton>
          </div>

          <UAlert
            v-if="activeRole && !activeRole.editable"
            color="info"
            variant="subtle"
            icon="i-lucide-shield-check"
            description="Super Admin has full access to every module and cannot be restricted."
          />

          <template v-else-if="activeRole">
            <p class="text-sm text-muted">
              Starting permissions copied to a user when they are created as, or switched to,
              <span class="font-medium">{{ activeRole.name }}</span>.
              Existing users are not changed — edit them in User permissions.
            </p>

            <PermissionChecklist
              v-model="roleSelection"
              :catalog="permissionCatalog"
              :disabled="!can('permission.update') || rolesSaving"
            />

            <p v-if="roleDirty" class="text-xs font-medium text-warning">
              Unsaved changes (switching role discards them)
            </p>
          </template>
        </template>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="rolesSaving" @click="showRolesModal = false">
          Close
        </UButton>
        <UButton
          v-if="can('permission.update') && activeRole?.editable"
          color="primary"
          :loading="rolesSaving"
          :disabled="!roleDirty"
          @click="saveRolePermissions"
        >
          Save {{ activeRole.name }} template
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- ── Per-user permissions modal ─────────────────────────────────────────── -->
  <UModal v-model:open="showUserPermsModal" title="User permissions" :ui="{ content: 'sm:max-w-2xl' }">
    <template #body>
      <div class="space-y-4">
        <USelectMenu
          v-model="editUserId"
          v-model:search-term="editUserSearch"
          :items="editUserOptions"
          value-key="value"
          ignore-filter
          :loading="editUserLoading"
          :disabled="userPermsSaving"
          placeholder="Select a user…"
          class="w-full"
        />

        <UAlert v-if="userPermsError" color="error" variant="subtle" :description="userPermsError" />

        <div v-else-if="userPermsBusy" class="space-y-2">
          <div v-for="n in 6" :key="n" class="h-8 animate-pulse rounded bg-[var(--ui-bg-elevated)]" />
        </div>

        <p v-else-if="editUserId === undefined" class="py-6 text-center text-sm text-muted">
          Pick a user to view and edit their permissions.
        </p>

        <UAlert
          v-else-if="editUserBypass"
          color="info"
          variant="subtle"
          icon="i-lucide-shield-check"
          description="Super Admin has full access to every module and cannot be restricted."
        />

        <template v-else>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-muted">
              Role <span class="font-medium">{{ editUserRole ?? '—' }}</span>. These permissions apply to this user only.
            </p>
            <UButton
              v-if="editUserTemplate && can('permission.update')"
              size="xs"
              color="neutral"
              variant="outline"
              icon="i-lucide-rotate-ccw"
              :disabled="userPermsSaving"
              @click="applyTemplateToUser"
            >
              Reset to {{ editUserTemplate.name }} template
            </UButton>
          </div>

          <PermissionChecklist
            v-model="editUserSelection"
            :catalog="permissionCatalog"
            :disabled="!can('permission.update') || userPermsSaving"
          />

          <p v-if="editUserDirty" class="text-xs font-medium text-warning">
            Unsaved changes (choosing another user discards them)
          </p>
        </template>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="userPermsSaving" @click="showUserPermsModal = false">
          Close
        </UButton>
        <UButton
          v-if="can('permission.update') && editUserId !== undefined && !editUserBypass"
          color="primary"
          :loading="userPermsSaving"
          :disabled="!editUserDirty"
          @click="saveUserPermissions"
        >
          Save permissions
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- ── Assigned users modal ───────────────────────────────────────────────── -->
  <UModal v-model:open="showUsersModal" :title="`Assigned users — ${usersTarget?.name ?? ''}`">
    <template #body>
      <div class="space-y-3">
        <div v-if="canManageUsers" class="space-y-1.5">
          <div class="flex gap-2">
            <USelectMenu
              v-model="addUserId"
              v-model:search-term="userSearch"
              :items="userOptions"
              value-key="value"
              ignore-filter
              :loading="userOptionsLoading"
              placeholder="Select a user to grant…"
              class="flex-1"
            />
            <UButton
              color="primary"
              icon="i-lucide-user-plus"
              :loading="assignBusy"
              :disabled="addUserId === undefined"
              @click="assignUser"
            >
              Add
            </UButton>
          </div>
          <p class="text-xs text-[var(--ui-text-muted)]">
            Grants this permission to the selected user only.
          </p>
        </div>

        <UAlert v-if="usersError" color="error" variant="subtle" :description="usersError" />
        <div v-else-if="usersBusy" class="py-6 flex justify-center">
          <div class="size-6 animate-spin rounded-full border-2 border-primary border-t-transparent" />
        </div>
        <div v-else-if="assignedUsers.length === 0" class="py-4 text-sm text-muted text-center">
          No users assigned to this permission.
        </div>
        <div v-else class="space-y-2 max-h-80 overflow-y-auto">
          <div
            v-for="user in assignedUsers"
            :key="user.id"
            class="flex items-center justify-between gap-3 rounded-lg border border-default p-3"
          >
            <div class="min-w-0">
              <p class="truncate text-sm font-medium">{{ user.name }}</p>
              <p class="truncate text-xs text-[var(--ui-text-muted)]">{{ user.email }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
              <UBadge :color="user.source === 'super_admin' ? 'info' : 'neutral'" variant="subtle" size="sm">
                {{ user.source === 'super_admin' ? 'Super Admin' : (user.role ?? 'No role') }}
              </UBadge>
              <UBadge :color="user.status === 'active' ? 'success' : 'error'" variant="subtle" size="sm">
                {{ user.status }}
              </UBadge>
              <UButton
                v-if="user.removable && can('permission.update')"
                size="xs"
                color="error"
                variant="ghost"
                icon="i-lucide-user-minus"
                :loading="revokingId === user.id"
                :aria-label="`Revoke from ${user.name}`"
                @click="revokeUser(user)"
              />
            </div>
          </div>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end">
        <UButton color="neutral" variant="outline" @click="showUsersModal = false">
          Close
        </UButton>
      </div>
    </template>
  </UModal>
</template>
