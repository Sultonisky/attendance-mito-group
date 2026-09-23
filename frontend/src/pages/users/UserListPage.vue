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
import { useAuthStore } from '../../stores/auth'
import {
  fetchUsers,
  createUser,
  updateUser,
  toggleUserStatus,
  deleteUser,
  fetchUserPermissions,
  syncUserPermissions,
  fetchPermissions,
  type UserRow,
  type UserFilters,
  type UserRole,
} from '../../services/userApi'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import { createSortableHeader, createStatusBadge, createTruncatedText } from '../../utils/dataTable'

const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()
const { can } = usePermission()
const toast = useAppToast()
const auth = useAuthStore()

// ── Table data ────────────────────────────────────────────────────────────────
const data = ref<UserRow[]>([])
const columnVisibility = ref<VisibilityState>()
const statusTab = ref('all')
const searchInput = ref('')

// ── Filters ───────────────────────────────────────────────────────────────────
const filters = reactive<UserFilters>({
  search:    '',
  role:      'all',
  status:    'all',
  per_page:  25,
  sort:      'name',
  direction: 'asc',
  page:      1,
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const statusOptions = [
  { label: 'All',      value: 'all'      },
  { label: 'Active',   value: 'active'   },
  { label: 'Inactive', value: 'inactive' },
]

const roleOptions = [
  { label: 'All roles',   value: 'all'         },
  { label: 'Super Admin', value: 'SUPER_ADMIN'  },
  { label: 'Admin',       value: 'ADMIN'        },
  { label: 'User',        value: 'USER'         },
]

const roleFormOptions = [
  { label: 'Super Admin', value: 'SUPER_ADMIN' as UserRole },
  { label: 'Admin',       value: 'ADMIN'        as UserRole },
  { label: 'User',        value: 'USER'         as UserRole },
]

const hideableColumns = [
  { id: 'name',        label: 'Name'       },
  { id: 'email',       label: 'Email'      },
  { id: 'role',        label: 'Role'       },
  { id: 'status',      label: 'Status'     },
  { id: 'created_at',  label: 'Created'    },
  { id: 'actions',     label: 'Actions'    },
]
const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const statusColor: Record<string, 'success' | 'error'> = {
  active:   'success',
  inactive: 'error',
}

const roleBadgeColor: Record<string, 'primary' | 'warning' | 'neutral'> = {
  SUPER_ADMIN: 'primary',
  ADMIN:       'warning',
  USER:        'neutral',
}

// ── Modal — create/edit ───────────────────────────────────────────────────────
const showFormModal = ref(false)
const formMode      = ref<'create' | 'edit'>('create')
const formBusy      = ref(false)
const formError     = ref('')
const editingId     = ref<number | null>(null)
const showPassword  = ref(false)

const form = reactive({
  name:     '',
  email:    '',
  password: '',
  role:     'USER' as UserRole,
})

// ── Modal — delete confirm ────────────────────────────────────────────────────
const showDeleteModal = ref(false)
const deleteTarget    = ref<UserRow | null>(null)
const deleteBusy      = ref(false)
const deleteError     = ref('')

// ── Modal — assign permissions ────────────────────────────────────────────────
const showPermissionsModal = ref(false)
const permissionsTarget    = ref<UserRow | null>(null)
const permissionsBusy      = ref(false)
const permissionsError     = ref('')
interface PermissionOption { id: number; name: string; description: string | null }
const allPermissions       = ref<PermissionOption[]>([])
const selectedPermissions  = ref<string[]>([])

// ── Columns ───────────────────────────────────────────────────────────────────
const columns = computed<TableColumn<UserRow>[]>(() => [
  {
    accessorKey: 'name',
    header: ({ column }) => createSortableHeader(column, 'Name'),
    cell: ({ row }) => h('div', { class: 'min-w-0' }, [
      createTruncatedText(row.original.name, 'font-medium text-sm'),
      row.original.has_employee
        ? h('p', { class: 'truncate text-xs text-[var(--ui-text-muted)]' }, 'Linked to employee')
        : null,
    ]),
  },
  {
    accessorKey: 'email',
    header: ({ column }) => createSortableHeader(column, 'Email'),
    cell: ({ row }) => createTruncatedText(row.original.email, 'text-sm text-[var(--ui-text-muted)]'),
  },
  {
    id: 'role',
    header: ({ column }) => createSortableHeader(column, 'Role'),
    accessorFn: (row) => row.role ?? '',
    cell: ({ row }) => {
      const role = row.original.role
      if (!role) return h('span', { class: 'text-[var(--ui-text-dimmed)] text-xs' }, '—')
      return h(resolveComponent('UBadge'), {
        color: roleBadgeColor[role] ?? 'neutral',
        variant: 'subtle',
        size: 'sm',
      }, () => role.replace('_', ' '))
    },
  },
  {
    accessorKey: 'status',
    header: ({ column }) => createSortableHeader(column, 'Status'),
    cell: ({ row }) => {
      const s = row.getValue<string>('status')
      return createStatusBadge(s, statusColor[s] ?? 'neutral')
    },
  },
  {
    accessorKey: 'created_at',
    header: ({ column }) => createSortableHeader(column, 'Created'),
    cell: ({ row }) => createTruncatedText(row.original.created_at, 'text-xs text-[var(--ui-text-muted)]'),
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const user = row.original
      const isSelf = user.id === auth.user?.id

      const items = [
        can('user.update') && {
          label: 'Edit',
          icon: 'i-lucide-pencil',
          onSelect: () => openEdit(user),
        },
        can('user.update') && {
          label: 'Permissions',
          icon: 'i-lucide-shield-check',
          onSelect: () => openPermissions(user),
        },
        can('user.update') && !isSelf && {
          label: user.status === 'active' ? 'Deactivate' : 'Activate',
          icon: user.status === 'active' ? 'i-lucide-user-x' : 'i-lucide-user-check',
          onSelect: () => handleToggle(user),
        },
        can('user.delete') && !isSelf && {
          label: 'Delete',
          icon: 'i-lucide-trash-2',
          color: 'error' as const,
          onSelect: () => confirmDelete(user),
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
watch(statusTab, (value) => {
  filters.status = value
  if (!ready.value) return
  meta.current_page = 1
  load()
})

watchDebounced(searchInput, (value) => {
  filters.search = value
  if (!ready.value) return
  meta.current_page = 1
  load()
}, { debounce: 400 })

watch(
  () => [filters.role, filters.per_page] as const,
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
  error.value = ''
  try {
    const res = await fetchUsers({
      search:    filters.search  || undefined,
      role:      filters.role,
      status:    filters.status,
      per_page:  filters.per_page,
      sort:      filters.sort,
      direction: filters.direction,
      page:      meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load users. Please try again.')
  } finally {
    loading.value = false
  }
}

function resetFilters(): void {
  filters.search    = ''
  filters.role      = 'all'
  filters.status    = 'all'
  filters.per_page  = 25
  filters.sort      = 'name'
  filters.direction = 'asc'
  statusTab.value   = 'all'
  searchInput.value = ''
  meta.current_page = 1
  load()
}

// ── CRUD ──────────────────────────────────────────────────────────────────────
function openCreate(): void {
  formMode.value  = 'create'
  editingId.value = null
  form.name       = ''
  form.email      = ''
  form.password   = ''
  form.role       = 'USER'
  showPassword.value = false
  formError.value = ''
  showFormModal.value = true
}

function openEdit(user: UserRow): void {
  formMode.value  = 'edit'
  editingId.value = user.id
  form.name       = user.name
  form.email      = user.email
  form.password   = ''
  form.role       = (user.role as UserRole) ?? 'USER'
  showPassword.value = false
  formError.value = ''
  showFormModal.value = true
}

async function submitForm(): Promise<void> {
  if (!form.name.trim())  { formError.value = 'Name is required.';  return }
  if (!form.email.trim()) { formError.value = 'Email is required.'; return }
  if (formMode.value === 'create' && !form.password) {
    formError.value = 'Password is required.'
    return
  }

  formBusy.value  = true
  formError.value = ''
  try {
    if (formMode.value === 'create') {
      await createUser({
        name:     form.name.trim(),
        email:    form.email.trim(),
        password: form.password,
        role:     form.role,
      })
      toast.success('User created')
    } else if (editingId.value !== null) {
      await updateUser(editingId.value, {
        name:     form.name.trim(),
        email:    form.email.trim(),
        password: form.password || null,
        role:     form.role,
      })
      toast.success('User updated')
    }
    showFormModal.value = false
    await load()
  } catch (e: unknown) {
    formError.value = e instanceof Error ? e.message : 'Failed to save. Please try again.'
  } finally {
    formBusy.value = false
  }
}

async function handleToggle(user: UserRow): Promise<void> {
  try {
    const res = await toggleUserStatus(user.id)
    const idx = data.value.findIndex(u => u.id === user.id)
    if (idx !== -1) data.value[idx] = { ...data.value[idx], status: res.data.status as 'active' | 'inactive' }
    toast.success(res.data.status === 'active' ? 'User activated' : 'User deactivated')
  } catch (e: unknown) {
    toast.fromError(e, 'Unable to update user status.')
    await load()
  }
}

function confirmDelete(user: UserRow): void {
  deleteTarget.value = user
  deleteError.value  = ''
  showDeleteModal.value = true
}

async function executeDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleteBusy.value  = true
  deleteError.value = ''
  try {
    const res = await deleteUser(deleteTarget.value.id)
    if (!res.success) {
      deleteError.value = res.message ?? 'Failed to delete user.'
      toast.error('Delete failed', deleteError.value)
      return
    }
    showDeleteModal.value = false
    deleteTarget.value    = null
    toast.success('User deleted')
    await load()
  } catch (e: unknown) {
    deleteError.value = e instanceof Error ? e.message : 'Failed to delete user.'
    toast.fromError(e, 'Unable to delete this user.')
  } finally {
    deleteBusy.value = false
  }
}

// ── Permissions assignment ────────────────────────────────────────────────────
async function openPermissions(user: UserRow): Promise<void> {
  permissionsTarget.value = user
  selectedPermissions.value = []
  permissionsError.value = ''
  permissionsBusy.value = true
  showPermissionsModal.value = true

  try {
    const [userPerms, allPerms] = await Promise.all([
      fetchUserPermissions(user.id),
      fetchPermissions(),
    ])
    selectedPermissions.value = userPerms.data
    allPermissions.value = allPerms.data
  } catch (e: unknown) {
    permissionsError.value = e instanceof Error ? e.message : 'Failed to load permissions.'
  } finally {
    permissionsBusy.value = false
  }
}

async function submitPermissions(): Promise<void> {
  if (!permissionsTarget.value) return
  permissionsBusy.value = true
  permissionsError.value = ''
  try {
    await syncUserPermissions(permissionsTarget.value.id, selectedPermissions.value)
    showPermissionsModal.value = false
    toast.success('Permissions updated')
  } catch (e: unknown) {
    permissionsError.value = e instanceof Error ? e.message : 'Failed to save permissions.'
  } finally {
    permissionsBusy.value = false
  }
}

onMounted(async () => {
  await load()
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="user-management">
    <template #header>
      <UDashboardNavbar title="User management">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            v-if="can('user.create')"
            color="primary"
            size="sm"
            icon="i-lucide-plus"
            @click="openCreate"
          >
            Add user
          </UButton>
          <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-filter-x" @click="resetFilters">
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
          <div class="flex items-center gap-2 text-sm text-muted">
            <UIcon name="i-lucide-users-2" class="size-4 shrink-0" />
            <span>
              <strong class="text-highlighted font-semibold">{{ meta.total }}</strong>
              user{{ meta.total !== 1 ? 's' : '' }}
            </span>
          </div>

          <DataTableToolbar
            v-model:search="searchInput"
            v-model:status="statusTab"
            v-model:per-page="filters.per_page"
            search-placeholder="Search name or email…"
            :status-options="statusOptions"
            :display-items="displayItems"
            show-per-page
          >
            <template #filters>
              <USelect
                v-model="filters.role"
                :items="roleOptions"
                value-key="value"
                label-key="label"
                class="w-36"
              />
            </template>
          </DataTableToolbar>

          <DataTable
            v-model:sorting="sorting"
            v-model:column-visibility="columnVisibility"
            :data="data"
            :columns="columns"
            :loading="loading"
            :meta="meta"
            manual-sorting
            empty-icon="i-lucide-user-x"
            empty-message="No users found for the selected filters."
            @update:page="goToPage($event, load)"
          />
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <!-- ── Create / Edit modal ───────────────────────────────────────────────── -->
  <UModal
    v-model:open="showFormModal"
    :title="formMode === 'create' ? 'Add user' : 'Edit user'"
  >
    <template #body>
      <div class="space-y-4">

        <UFormField label="Full name" required>
          <UInput v-model="form.name" placeholder="Jane Doe" class="w-full" />
        </UFormField>

        <UFormField label="Email address" required>
          <UInput v-model="form.email" type="email" placeholder="jane@mito.co.id" class="w-full" />
        </UFormField>

        <UFormField
          :label="formMode === 'create' ? 'Password' : 'New password'"
          :hint="formMode === 'edit' ? 'Leave blank to keep current password' : undefined"
          :required="formMode === 'create'"
        >
          <div class="relative">
            <UInput
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="Min 8 chars, mixed case + number"
              class="w-full"
            />
            <button
              type="button"
              class="absolute inset-y-0 right-2.5 flex items-center text-[var(--ui-text-muted)] hover:text-[var(--ui-text)]"
              :aria-label="showPassword ? 'Hide password' : 'Show password'"
              @click="showPassword = !showPassword"
            >
              <UIcon :name="showPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'" class="size-4" />
            </button>
          </div>
        </UFormField>

        <UFormField label="Role" required>
          <USelect
            v-model="form.role"
            :items="roleFormOptions"
            value-key="value"
            label-key="label"
            class="w-full"
          />
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
          {{ formMode === 'create' ? 'Add user' : 'Save changes' }}
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- ── Delete confirm modal ───────────────────────────────────────────────── -->
  <UModal v-model:open="showDeleteModal" title="Delete user">
    <template #body>
      <div class="space-y-3">
        <p class="text-sm text-muted">
          Are you sure you want to delete
          <strong class="text-highlighted">{{ deleteTarget?.name }}</strong>
          (<span class="font-mono text-xs">{{ deleteTarget?.email }}</span>)?
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

  <!-- ── Assign permissions modal ────────────────────────────────────────────── -->
  <UModal v-model:open="showPermissionsModal" :title="`Permissions: ${permissionsTarget?.name ?? ''}`">
    <template #body>
      <div class="space-y-4">
        <p class="text-sm text-muted">
          Select the permissions to assign to this user.
        </p>

        <div v-if="permissionsBusy" class="space-y-2">
          <div v-for="n in 6" :key="n" class="h-8 animate-pulse rounded bg-[var(--ui-bg-elevated)]" />
        </div>

        <template v-else>
          <div class="max-h-80 space-y-2 overflow-y-auto rounded-lg border border-[var(--ui-border)] p-3">
            <label
              v-for="item in allPermissions"
              :key="item.id"
              class="flex items-start gap-3 rounded-md px-2 py-2 hover:bg-[var(--ui-bg-elevated)]"
            >
              <UCheckbox
                :model-value="selectedPermissions.includes(item.name)"
                @update:model-value="(checked: boolean) => {
                  selectedPermissions = checked
                    ? [...selectedPermissions, item.name]
                    : selectedPermissions.filter((p) => p !== item.name)
                }"
                class="mt-0.5"
              />
              <span class="flex flex-col gap-0.5">
                <span class="text-sm font-mono">{{ item.name }}</span>
                <span v-if="item.description" class="text-xs text-[var(--ui-text-muted)]">
                  {{ item.description }}
                </span>
              </span>
            </label>

            <p v-if="!allPermissions.length" class="text-sm text-muted">
              No permissions available.
            </p>
          </div>

          <UAlert v-if="permissionsError" color="error" variant="subtle" :description="permissionsError" />
        </template>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="permissionsBusy" @click="showPermissionsModal = false">
          Cancel
        </UButton>
        <UButton color="primary" :loading="permissionsBusy" @click="submitPermissions">
          Save permissions
        </UButton>
      </div>
    </template>
  </UModal>
</template>
