<script setup lang="ts">
import { computed, h, onMounted, ref, resolveComponent, watch } from 'vue'
import { watchDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { SortingState, VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../../composables/useReportPage'
import { useDataTableDisplay } from '../../../composables/useDataTableDisplay'
import { usePermission } from '../../../features/auth/composables/usePermission'
import { useAppToast } from '../../../composables/useAppToast'
import {
  fetchHrisEmployees,
  type HrisEmployeeFilterOptions,
  type HrisEmployeeRow,
} from '../../../services/employeeApi'
import {
  fetchEmployeeWorkLocations,
  fetchHrisEmployeeWorkLocations,
  syncHrisEmployeeWorkLocations,
  type EmployeeWorkLocationRow,
} from '../../../services/employeeWorkLocationApi'
import DataTableToolbar from '../../../components/DataTableToolbar.vue'
import DataTable from '../../../components/DataTable.vue'
import DashboardNavbarTitle from '../../../components/DashboardNavbarTitle.vue'
import { createSortableHeader, createTruncatedText } from '../../../utils/dataTable'

const ALL_FILTERS = '__all__'

const { loading, error, filterError, meta, clearErrors, handleApiError, applyMeta, goToPage } = useReportPage()
const { can } = usePermission()
const toast = useAppToast()

const data = ref<HrisEmployeeRow[]>([])
const columnVisibility = ref<VisibilityState>()
const sorting = ref<SortingState>([])
const searchInput = ref('')
const workLocation = ref(ALL_FILTERS)
const workArea = ref(ALL_FILTERS)
const filterOptions = ref<HrisEmployeeFilterOptions>({ work_locations: [], work_areas: [] })
const perPage = ref(25)
const ready = ref(false)
let activeRequest = 0

const canManageAssignments = computed(() =>
  can('employee_work_location.view') && can('employee_work_location.update'),
)

const hideableColumns = [
  { id: 'employee_id', label: 'Employee ID' },
  { id: 'nik', label: 'NIK' },
  { id: 'full_name', label: 'Name' },
  { id: 'job_position', label: 'Job position' },
  { id: 'lokasi_kerja', label: 'Work location' },
  { id: 'area_kerja', label: 'Work area' },
  { id: 'actions', label: 'Locations' },
]
const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const workLocationOptions = computed(() => [
  { label: 'All work locations', value: ALL_FILTERS },
  ...filterOptions.value.work_locations.map((location) => ({ label: location, value: location })),
])
const workAreaOptions = computed(() => [
  { label: 'All work areas', value: ALL_FILTERS },
  ...filterOptions.value.work_areas.map((area) => ({ label: area, value: area })),
])

const showLocationsModal = ref(false)
const locationTarget = ref<HrisEmployeeRow | null>(null)
const assignedLocations = ref<EmployeeWorkLocationRow[]>([])
const availableLocations = ref<EmployeeWorkLocationRow[]>([])
const locationPick = ref(ALL_FILTERS)
const locationsLoading = ref(false)
const locationsSaving = ref(false)
const locationsError = ref('')

const hrisWorkLocation = computed(() => locationTarget.value?.lokasi_kerja?.trim() ?? '')
const hrisWorkArea = computed(() => locationTarget.value?.area_kerja?.trim() ?? '')
const hasHrisWorkLocationData = computed(() => Boolean(hrisWorkLocation.value && hrisWorkArea.value))

const availableLocationOptions = computed(() => [
  { label: 'Select an attendance location', value: ALL_FILTERS },
  ...availableLocations.value
    .filter((location) => !assignedLocations.value.some((assigned) => assigned.id === location.id))
    .map((location) => ({
      label: `${location.city} · ${location.name} (${location.area_type_label})`,
      value: String(location.id),
    })),
])

const columns = computed<TableColumn<HrisEmployeeRow>[]>(() => [
  {
    accessorKey: 'employee_id',
    header: ({ column }) => createSortableHeader(column, 'Employee ID'),
    cell: ({ row }) => createTruncatedText(row.original.employee_id, 'text-sm font-mono'),
  },
  {
    accessorKey: 'nik',
    header: ({ column }) => createSortableHeader(column, 'NIK'),
    cell: ({ row }) => createTruncatedText(row.original.nik, 'text-sm font-mono'),
  },
  {
    accessorKey: 'full_name',
    header: ({ column }) => createSortableHeader(column, 'Name'),
    cell: ({ row }) => createTruncatedText(row.original.full_name, 'text-sm font-medium'),
  },
  {
    accessorKey: 'job_position',
    header: ({ column }) => createSortableHeader(column, 'Job title'),
    cell: ({ row }) => createTruncatedText(row.original.job_position, 'text-sm text-[var(--ui-text-muted)]'),
  },
  {
    accessorKey: 'lokasi_kerja',
    header: ({ column }) => createSortableHeader(column, 'Location'),
    cell: ({ row }) => createTruncatedText(row.original.lokasi_kerja, 'text-sm text-[var(--ui-text-muted)]'),
  },
  {
    accessorKey: 'area_kerja',
    header: ({ column }) => createSortableHeader(column, 'Work area'),
    cell: ({ row }) => createTruncatedText(row.original.area_kerja, 'text-sm text-[var(--ui-text-muted)]'),
  },
  {
    id: 'actions',
    header: 'Locations',
    cell: ({ row }) => {
      if (!canManageAssignments.value) return null

      return h(resolveComponent('UButton'), {
        size: 'xs',
        color: 'primary',
        variant: 'outline',
        icon: 'i-lucide-map-pin',
        onClick: () => { void openLocationsModal(row.original) },
      }, () => 'Set')
    },
  },
])

async function load(): Promise<void> {
  const requestId = ++activeRequest
  loading.value = true
  clearErrors()

  try {
    const response = await fetchHrisEmployees({
      search: searchInput.value.trim() || undefined,
      per_page: perPage.value,
      page: meta.current_page,
      work_location: workLocation.value === ALL_FILTERS ? undefined : workLocation.value,
      work_area: workArea.value === ALL_FILTERS ? undefined : workArea.value,
    })

    if (requestId !== activeRequest) return

    data.value = response.data
    filterOptions.value = response.filters
    applyMeta(response.meta)
  } catch (err) {
    if (requestId !== activeRequest) return
    await handleApiError(err, 'Unable to load employees from HRIS.')
  } finally {
    if (requestId === activeRequest) loading.value = false
  }
}

function resetFilters(): void {
  const changed = searchInput.value !== ''
    || workLocation.value !== ALL_FILTERS
    || workArea.value !== ALL_FILTERS

  searchInput.value = ''
  workLocation.value = ALL_FILTERS
  workArea.value = ALL_FILTERS
  meta.current_page = 1

  if (!changed) void load()
}

function addSelectedLocation(): void {
  const location = availableLocations.value.find((item) => String(item.id) === locationPick.value)
  if (!location || assignedLocations.value.some((item) => item.id === location.id)) return
  assignedLocations.value.push(location)
  locationPick.value = ALL_FILTERS
}

function removeAssignedLocation(id: number): void {
  assignedLocations.value = assignedLocations.value.filter((location) => location.id !== id)
}

async function openLocationsModal(employee: HrisEmployeeRow): Promise<void> {
  locationTarget.value = employee
  assignedLocations.value = []
  availableLocations.value = []
  locationPick.value = ALL_FILTERS
  locationsError.value = ''
  locationsLoading.value = true
  showLocationsModal.value = true

  if (!employee.nik) {
    locationsError.value = 'HRIS did not provide a NIK for this employee; attendance locations cannot be assigned.'
    locationsLoading.value = false
    return
  }

  try {
    const [assigned, available] = await Promise.all([
      fetchHrisEmployeeWorkLocations(employee.nik),
      hasHrisWorkLocationData.value
        ? fetchEmployeeWorkLocations({
          status: 'active',
          per_page: 100,
          sort: 'city',
          city: hrisWorkLocation.value,
          area_type: hrisWorkArea.value,
        })
        : Promise.resolve(null),
    ])
    assignedLocations.value = assigned.data
    availableLocations.value = available?.data ?? []
  } catch (err) {
    locationsError.value = err instanceof Error ? err.message : 'Failed to load attendance locations.'
  } finally {
    locationsLoading.value = false
  }
}

async function saveLocations(): Promise<void> {
  if (!locationTarget.value?.nik) return

  locationsSaving.value = true
  locationsError.value = ''
  try {
    await syncHrisEmployeeWorkLocations(
      locationTarget.value.nik,
      assignedLocations.value.map((location) => location.id),
    )
    toast.success('Employee attendance locations updated successfully.')
    showLocationsModal.value = false
  } catch (err) {
    locationsError.value = err instanceof Error ? err.message : 'Failed to save attendance locations.'
  } finally {
    locationsSaving.value = false
  }
}

watchDebounced(searchInput, () => {
  if (!ready.value) return
  meta.current_page = 1
  void load()
}, { debounce: 400 })

watch(perPage, () => {
  if (!ready.value) return
  meta.current_page = 1
  void load()
})

watch([workLocation, workArea], () => {
  if (!ready.value) return
  meta.current_page = 1
  void load()
})

onMounted(async () => {
  await load()
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="employee-management">
    <template #header>
      <UDashboardNavbar>
        <template #title>
          <DashboardNavbarTitle />
        </template>
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
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
      <div class="space-y-4 p-4 sm:p-6">
        <UAlert
          color="info"
          variant="subtle"
          icon="i-lucide-info"
          title="Data sourced from HRIS"
          description="This employee list is read-only and loaded directly from HRIS. Employee data must be updated in HRIS."
        />

        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Failed to load HRIS employees"
          :description="error"
        >
          <template #actions>
            <UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">
              Try again
            </UButton>
          </template>
        </UAlert>
        <UAlert
          v-else-if="filterError"
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Invalid filter"
          :description="filterError"
        />

        <template v-else>
          <DataTableToolbar
            v-model:search="searchInput"
            v-model:per-page="perPage"
            search-placeholder="Search employee ID, NIK, name, job title, location, or area..."
            :display-items="displayItems"
            show-per-page
          >
            <template #filters>
              <USelect
                v-model="workLocation"
                :items="workLocationOptions"
                value-key="value"
                label-key="label"
                aria-label="Filter by work location"
                class="w-48"
              />
              <USelect
                v-model="workArea"
                :items="workAreaOptions"
                value-key="value"
                label-key="label"
                aria-label="Filter by work area"
                class="w-48"
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
            :get-row-id="employee => employee.employee_id"
            empty-icon="i-lucide-users"
            empty-message="No employees in HRIS match your search."
            @update:page="goToPage($event, load)"
          />
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="showLocationsModal" title="Manage employee attendance locations">
    <template #body>
      <div class="space-y-4">
        <div v-if="locationTarget" class="rounded-md border border-default p-3">
          <p class="font-medium">{{ locationTarget.full_name ?? 'Name unavailable' }}</p>
          <p class="text-xs text-muted">
            Employee ID {{ locationTarget.employee_id }} · NIK {{ locationTarget.nik }}
          </p>
        </div>

        <UAlert
          v-if="locationsError"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          :description="locationsError"
        />

        <div v-if="locationsLoading" class="py-8 text-center text-sm text-muted">
          Loading attendance locations...
        </div>
        <template v-else>
          <UAlert
            v-if="!hasHrisWorkLocationData"
            color="warning"
            variant="subtle"
            icon="i-lucide-info"
            title="HRIS work location details are incomplete"
            description="This employee needs a work location and a supported work area in HRIS before matching attendance locations."
          />
          <UAlert
            v-else-if="!availableLocations.length"
            color="info"
            variant="subtle"
            icon="i-lucide-map-pin"
            title="No matching attendance location"
            :description="`Create a work location with '${hrisWorkLocation}' and the matching work area in Employees > Work Locations. Enter its name, address, coordinates, and radius manually there.`"
          />
          <UFormField v-if="canManageAssignments" label="Add work location">
            <div class="flex gap-2">
              <USelect
                v-model="locationPick"
                :items="availableLocationOptions"
                value-key="value"
                label-key="label"
                class="min-w-0 flex-1"
              />
              <UButton
                color="primary"
                variant="subtle"
                icon="i-lucide-plus"
                :disabled="locationPick === ALL_FILTERS"
                @click="addSelectedLocation"
              >
                Add
              </UButton>
            </div>
          </UFormField>

          <div class="space-y-2">
            <p class="text-sm font-medium">Locations allowed for attendance</p>
            <div
              v-if="!assignedLocations.length"
              class="rounded-md border border-dashed border-default px-3 py-2.5 text-sm text-muted"
            >
              No attendance locations assigned.
            </div>
            <div v-else class="space-y-2">
              <div
                v-for="location in assignedLocations"
                :key="location.id"
                class="flex items-center justify-between gap-3 rounded-md border border-default px-3 py-2"
              >
                <div class="min-w-0">
                  <p class="truncate text-sm font-medium">{{ location.name }}</p>
                  <p class="truncate text-xs text-muted">
                    {{ location.city }} · {{ location.area_type_label }}
                  </p>
                </div>
                <UButton
                  v-if="canManageAssignments"
                  color="error"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-x"
                  aria-label="Remove attendance location"
                  @click="removeAssignedLocation(location.id)"
                />
              </div>
            </div>
          </div>
        </template>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" @click="showLocationsModal = false">
          Close
        </UButton>
        <UButton
          v-if="canManageAssignments && locationTarget?.nik"
          color="primary"
          :loading="locationsSaving"
          :disabled="locationsLoading"
          @click="saveLocations"
        >
          Save locations
        </UButton>
      </div>
    </template>
  </UModal>
</template>
