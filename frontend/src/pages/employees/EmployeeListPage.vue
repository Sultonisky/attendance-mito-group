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
  fetchEmployees,
  createEmployee,
  updateEmployee,
  deleteEmployee,
  type EmployeeRow,
  type EmployeeMeta,
  type CreateEmployeePayload,
  type UpdateEmployeePayload,
} from '../../services/employeeApi'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import { createSortableHeader, createStatusBadge } from '../../utils/dataTable'

const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()
const { can } = usePermission()

// ── Table data ────────────────────────────────────────────────────────────────
const data = ref<EmployeeRow[]>([])
const columnVisibility = ref<VisibilityState>()
const searchInput = ref('')

// ── Filters ───────────────────────────────────────────────────────────────────
const filters = reactive({
  search: '',
  per_page: 25,
  sort: 'employee_code',
  direction: 'asc' as 'asc' | 'desc',
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const employmentStatusOptions = [
  { label: 'Permanent', value: 'permanent' },
  { label: 'Contract', value: 'contract' },
  { label: 'Probation', value: 'probation' },
  { label: 'Outsource', value: 'outsource' },
]

const hideableColumns = [
  { id: 'employee_code', label: 'Code' },
  { id: 'full_name', label: 'Name' },
  { id: 'email', label: 'Email' },
  { id: 'employment_status', label: 'Status' },
  { id: 'department', label: 'Department' },
  { id: 'join_date', label: 'Join date' },
  { id: 'actions', label: 'Actions' },
]
const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

// ── Modal — create/edit ───────────────────────────────────────────────────────
const showFormModal = ref(false)
const formMode      = ref<'create' | 'edit'>('create')
const formBusy      = ref(false)
const formError     = ref('')
const editingId     = ref<number | null>(null)

const form = reactive({
  employee_code: '',
  full_name: '',
  email: '',
  phone: '',
  employment_status: 'permanent' as 'permanent' | 'contract' | 'probation' | 'outsource',
  join_date: '',
  end_date: '',
  job_position: '',
  department: '',
  division: '',
  branch: '',
  job_level: '',
  grade: '',
  direct_superior_id: null as number | null,
  indirect_superior_id: null as number | null,
  user_id: null as number | null,
})

const showPassword = ref(false)

// ── Modal — delete confirm ────────────────────────────────────────────────────
const showDeleteModal = ref(false)
const deleteTarget    = ref<EmployeeRow | null>(null)
const deleteBusy      = ref(false)
const deleteError     = ref('')

// ── Columns ───────────────────────────────────────────────────────────────────
const columns = computed<TableColumn<EmployeeRow>[]>(() => [
  {
    accessorKey: 'employee_code',
    header: ({ column }) => createSortableHeader(column, 'Code'),
    cell: ({ row }) => h('span', { class: 'text-sm font-mono' }, row.original.employee_code),
  },
  {
    accessorKey: 'full_name',
    header: ({ column }) => createSortableHeader(column, 'Name'),
    cell: ({ row }) => h('span', { class: 'text-sm font-medium' }, row.original.full_name),
  },
  {
    accessorKey: 'email',
    header: ({ column }) => createSortableHeader(column, 'Email'),
    cell: ({ row }) => h('span', { class: 'text-sm text-[var(--ui-text-muted)]' }, row.original.email ?? '—'),
  },
  {
    id: 'employment_status',
    header: ({ column }) => createSortableHeader(column, 'Status'),
    accessorFn: (row) => row.employment_status ?? '',
    cell: ({ row }) => {
      const status = row.original.employment_status
      const colorMap: Record<string, string> = {
        permanent: 'success',
        contract: 'info',
        probation: 'warning',
        outsource: 'neutral',
      }
      return h('span', { class: 'text-xs' }, [
        h(resolveComponent('UBadge'), {
          color: colorMap[status] ?? 'neutral',
          variant: 'subtle',
          size: 'sm',
        }, () => status.replace('_', ' ')),
      ])
    },
  },
  {
    accessorKey: 'department',
    header: ({ column }) => createSortableHeader(column, 'Department'),
    cell: ({ row }) => h('span', { class: 'text-sm text-[var(--ui-text-muted)]' }, row.original.department ?? '—'),
  },
  {
    accessorKey: 'join_date',
    header: ({ column }) => createSortableHeader(column, 'Join date'),
    cell: ({ row }) => h('span', { class: 'text-xs text-[var(--ui-text-muted)]' }, row.original.join_date ?? '—'),
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const items = [
        can('employees.update') && {
          label: 'Edit',
          icon: 'i-lucide-pencil',
          onSelect: () => openEdit(row.original),
        },
        can('employees.delete') && {
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
    const res = await fetchEmployees({
      ...filters,
      page: meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load employees. Please try again.')
  } finally {
    loading.value = false
  }
}

function resetFilters(): void {
  searchInput.value = ''
  meta.current_page = 1
  load()
}

// ── CRUD ──────────────────────────────────────────────────────────────────────
function openCreate(): void {
  formMode.value = 'create'
  editingId.value = null
  form.employee_code = ''
  form.full_name = ''
  form.email = ''
  form.phone = ''
  form.employment_status = 'permanent'
  form.join_date = ''
  form.end_date = ''
  form.job_position = ''
  form.department = ''
  form.division = ''
  form.branch = ''
  form.job_level = ''
  form.grade = ''
  form.direct_superior_id = null
  form.indirect_superior_id = null
  form.user_id = null
  formError.value = ''
  showFormModal.value = true
}

function openEdit(employee: EmployeeRow): void {
  formMode.value = 'edit'
  editingId.value = employee.id
  form.employee_code = employee.employee_code
  form.full_name = employee.full_name
  form.email = employee.email ?? ''
  form.phone = employee.phone ?? ''
  form.employment_status = employee.employment_status as 'permanent' | 'contract' | 'probation' | 'outsource'
  form.join_date = employee.join_date ?? ''
  form.end_date = employee.end_date ?? ''
  form.job_position = employee.job_position ?? ''
  form.department = employee.department ?? ''
  form.division = employee.division ?? ''
  form.branch = employee.branch ?? ''
  form.job_level = employee.job_level ?? ''
  form.grade = employee.grade ?? ''
  form.direct_superior_id = employee.direct_superior_id
  form.indirect_superior_id = employee.indirect_superior_id
  form.user_id = employee.user_id
  formError.value = ''
  showFormModal.value = true
}

async function submitForm(): Promise<void> {
  if (!form.employee_code.trim()) { formError.value = 'Employee code is required.'; return }
  if (!form.full_name.trim()) { formError.value = 'Full name is required.'; return }
  if (!form.join_date) { formError.value = 'Join date is required.'; return }

  formBusy.value = true
  formError.value = ''
  try {
    const payload: CreateEmployeePayload | UpdateEmployeePayload = {
      employee_code: form.employee_code.trim(),
      full_name: form.full_name.trim(),
      email: form.email.trim() || null,
      phone: form.phone.trim() || null,
      employment_status: form.employment_status,
      join_date: form.join_date,
      end_date: form.end_date || null,
      job_position: form.job_position.trim() || null,
      department: form.department.trim() || null,
      division: form.division.trim() || null,
      branch: form.branch.trim() || null,
      job_level: form.job_level.trim() || null,
      grade: form.grade.trim() || null,
      direct_superior_id: form.direct_superior_id,
      indirect_superior_id: form.indirect_superior_id,
      user_id: form.user_id,
    }

    if (formMode.value === 'create') {
      await createEmployee(payload as CreateEmployeePayload)
    } else if (editingId.value !== null) {
      await updateEmployee(editingId.value, payload as UpdateEmployeePayload)
    }
    showFormModal.value = false
    await load()
  } catch (e: unknown) {
    formError.value = e instanceof Error ? e.message : 'Failed to save. Please try again.'
  } finally {
    formBusy.value = false
  }
}

function confirmDelete(employee: EmployeeRow): void {
  deleteTarget.value = employee
  deleteError.value = ''
  showDeleteModal.value = true
}

async function executeDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleteBusy.value = true
  deleteError.value = ''
  try {
    const res = await deleteEmployee(deleteTarget.value.id)
    if (!res.success) {
      deleteError.value = 'Failed to delete employee.'
      return
    }
    showDeleteModal.value = false
    deleteTarget.value = null
    await load()
  } catch (e: unknown) {
    deleteError.value = e instanceof Error ? e.message : 'Failed to delete employee.'
  } finally {
    deleteBusy.value = false
  }
}

onMounted(async () => {
  await load()
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="employee-management">
    <template #header>
      <UDashboardNavbar title="Employee management">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            v-if="can('employees.create')"
            color="primary"
            size="sm"
            icon="i-lucide-plus"
            @click="openCreate"
          >
            Add employee
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
          <DataTableToolbar
            v-model:search="searchInput"
            search-placeholder="Search employee code or name…"
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
            empty-icon="i-lucide-users"
            empty-message="No employees found."
            @update:page="goToPage($event, load)"
          >
            <template #empty-extra>
              <UButton v-if="can('employees.create')" color="primary" variant="subtle" size="sm" icon="i-lucide-plus" @click="openCreate">
                Add employee
              </UButton>
            </template>
          </DataTable>
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <!-- ── Create / Edit modal ───────────────────────────────────────────────── -->
  <UModal
    v-model:open="showFormModal"
    :title="formMode === 'create' ? 'Add employee' : 'Edit employee'"
  >
    <template #body>
      <div class="space-y-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <UFormField label="Employee code" required>
            <UInput v-model="form.employee_code" placeholder="EMP001" class="w-full" />
          </UFormField>

          <UFormField label="Full name" required>
            <UInput v-model="form.full_name" placeholder="John Doe" class="w-full" />
          </UFormField>

          <UFormField label="Email">
            <UInput v-model="form.email" type="email" placeholder="john@example.com" class="w-full" />
          </UFormField>

          <UFormField label="Phone">
            <UInput v-model="form.phone" placeholder="+62 812 3456 7890" class="w-full" />
          </UFormField>

          <UFormField label="Employment status" required>
            <USelect v-model="form.employment_status" :items="employmentStatusOptions" value-key="value" label-key="label" class="w-full" />
          </UFormField>

          <UFormField label="Join date" required>
            <UInput v-model="form.join_date" type="date" class="w-full" />
          </UFormField>

          <UFormField label="End date">
            <UInput v-model="form.end_date" type="date" class="w-full" />
          </UFormField>

          <UFormField label="Job position">
            <UInput v-model="form.job_position" placeholder="Software Engineer" class="w-full" />
          </UFormField>

          <UFormField label="Department">
            <UInput v-model="form.department" placeholder="Engineering" class="w-full" />
          </UFormField>

          <UFormField label="Division">
            <UInput v-model="form.division" placeholder="Product" class="w-full" />
          </UFormField>

          <UFormField label="Branch">
            <UInput v-model="form.branch" placeholder="Jakarta" class="w-full" />
          </UFormField>

          <UFormField label="Job level">
            <UInput v-model="form.job_level" placeholder="L3" class="w-full" />
          </UFormField>

          <UFormField label="Grade">
            <UInput v-model="form.grade" placeholder="A" class="w-full" />
          </UFormField>
        </div>

        <UAlert v-if="formError" color="error" variant="subtle" :description="formError" />
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="formBusy" @click="showFormModal = false">
          Cancel
        </UButton>
        <UButton color="primary" :loading="formBusy" @click="submitForm">
          {{ formMode === 'create' ? 'Add employee' : 'Save changes' }}
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- ── Delete confirm modal ───────────────────────────────────────────────── -->
  <UModal v-model:open="showDeleteModal" title="Delete employee">
    <template #body>
      <div class="space-y-3">
        <p class="text-sm text-muted">
          Are you sure you want to delete employee
          <strong class="text-highlighted">{{ deleteTarget?.full_name }}</strong>
          ({{ deleteTarget?.employee_code }})?
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
</template>
