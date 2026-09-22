<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, resolveComponent, watch } from 'vue'
import { watchDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../composables/useReportPage'
import { useDataTableSort } from '../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../composables/useDataTableDisplay'
import { usePermission } from '../../features/auth/composables/usePermission'
import {
  fetchPermissions,
  fetchPermissionUsers,
  createPermission,
  updatePermission,
  deletePermission,
  type PermissionRow,
  type PermissionUser,
} from '../../services/permissionApi'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import { createSortableHeader } from '../../utils/dataTable'

const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()
const { can } = usePermission()

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

// ── Columns ───────────────────────────────────────────────────────────────────
const columns = computed<TableColumn<PermissionRow>[]>(() => [
  {
    accessorKey: 'name',
    header: ({ column }) => createSortableHeader(column, 'Name'),
    cell: ({ row }) => h('span', { class: 'text-sm font-mono' }, row.original.name),
  },
  {
    accessorKey: 'description',
    header: ({ column }) => createSortableHeader(column, 'Description'),
    cell: ({ row }) => h('span', { class: 'text-xs text-[var(--ui-text-muted)]' }, row.original.description ?? '—'),
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
          color: 'neutral',
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
  error.value = ''
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
    } else if (editingId.value !== null) {
      await updatePermission(editingId.value, { name: form.name.trim() })
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
      return
    }
    showDeleteModal.value = false
    deleteTarget.value = null
    await load()
  } catch (e: unknown) {
    deleteError.value = e instanceof Error ? e.message : 'Failed to delete permission.'
  } finally {
    deleteBusy.value = false
  }
}

function openUsers(permission: PermissionRow): void {
  usersTarget.value = permission
  usersError.value = ''
  assignedUsers.value = []
  showUsersModal.value = true
  loadUsers(permission.id)
}

async function loadUsers(permissionId: number): Promise<void> {
  usersBusy.value = true
  usersError.value = ''
  try {
    const res = await fetchPermissionUsers(permissionId)
    assignedUsers.value = res.data
  } catch (e: unknown) {
    usersError.value = e instanceof Error ? e.message : 'Failed to load assigned users.'
  } finally {
    usersBusy.value = false
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

  <!-- ── Assigned users modal ───────────────────────────────────────────────── -->
  <UModal v-model:open="showUsersModal" :title="`Assigned users — ${usersTarget?.name ?? ''}`">
    <template #body>
      <div class="space-y-3">
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
            class="flex items-center justify-between rounded-lg border border-default p-3"
          >
            <div>
              <p class="text-sm font-medium">{{ user.name }}</p>
              <p class="text-xs text-[var(--ui-text-muted)]">{{ user.email }}</p>
            </div>
            <UBadge :color="user.status === 'active' ? 'success' : 'error'" variant="subtle" size="sm">
              {{ user.status }}
            </UBadge>
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
