<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import AppIcon from '../../../components/AppIcon.vue'
import { ApiError } from '../../../services/apiClient'
import {
  fetchOutsourceIncentives,
  fetchOutsourcePayslips,
  type OutsourceIncentive,
  type OutsourcePayslip,
} from '../../../services/outsourcePayrollService'

defineProps<{
  outsourceName: string
}>()

const emit = defineEmits<{
  close: []
}>()

type PayrollTab = 'payslip' | 'incentive'

const activeTab = ref<PayrollTab>('payslip')
const selectedPeriod = ref('')
const payslips = ref<OutsourcePayslip[]>([])
const incentives = ref<OutsourceIncentive[]>([])
const isLoading = ref(false)
const errorMessage = ref('')

const periods = computed(() =>
  [...new Set([...payslips.value, ...incentives.value].map((record) => record.period))]
    .filter((period) => /^\d{4}-(0[1-9]|1[0-2])$/.test(period))
    .sort((left, right) => right.localeCompare(left)),
)

const selectedPeriodIndex = computed(() => periods.value.indexOf(selectedPeriod.value))

const visiblePayslips = computed(() =>
  payslips.value.filter((record) => !selectedPeriod.value || record.period === selectedPeriod.value),
)

const visibleIncentives = computed(() =>
  incentives.value.filter((record) => !selectedPeriod.value || record.period === selectedPeriod.value),
)

const monthFormatter = new Intl.DateTimeFormat('id-ID', {
  month: 'long',
  year: 'numeric',
  timeZone: 'Asia/Jakarta',
})

const currencyFormatter = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
})

watch(periods, (available) => {
  if (!available.includes(selectedPeriod.value)) {
    selectedPeriod.value = available[0] ?? ''
  }
}, { immediate: true })

function formatPeriod(period: string): string {
  const [year, month] = period.split('-').map(Number)
  if (!year || !month) return period
  return monthFormatter.format(new Date(Date.UTC(year, month - 1, 1, -7)))
}

function navigatePeriod(direction: -1 | 1): void {
  const nextPeriod = periods.value[selectedPeriodIndex.value + direction]
  if (nextPeriod) selectedPeriod.value = nextPeriod
}

function formatCurrency(value: number): string {
  return currencyFormatter.format(Number.isFinite(value) ? value : 0)
}

async function loadPayroll(): Promise<void> {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const [payslipRows, incentiveRows] = await Promise.all([
      fetchOutsourcePayslips(),
      fetchOutsourceIncentives(),
    ])
    payslips.value = payslipRows
    incentives.value = incentiveRows
  } catch (error: unknown) {
    if (error instanceof ApiError && error.status === 401) {
      errorMessage.value = 'Sesi telah berakhir. Silakan login kembali untuk melihat payroll.'
    } else if (error instanceof ApiError && error.status === 502) {
      errorMessage.value = 'Data payroll sedang tidak dapat dihubungi. Silakan coba lagi nanti.'
    } else {
      errorMessage.value = 'Gagal memuat data payroll. Periksa koneksi lalu coba lagi.'
    }
  } finally {
    isLoading.value = false
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') emit('close')
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  void loadPayroll()
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div class="os-payroll" aria-label="Payslip dan insentif">
    <div class="os-payroll__inner">
      <header class="os-payroll__bar">
        <button type="button" class="os-payroll__icon-btn" aria-label="Kembali ke presensi" @click="emit('close')">
          <AppIcon name="ArrowLeft" :size="18" :stroke-width="2.3" aria-hidden="true" />
        </button>
        <div class="os-payroll__title">
          <p class="os-payroll__eyebrow">PAYROLL OUTSOURCE</p>
          <strong>{{ outsourceName || 'Personel' }}</strong>
        </div>
        <button
          type="button"
          class="os-payroll__icon-btn"
          aria-label="Muat ulang"
          :disabled="isLoading"
          @click="loadPayroll"
        >
          <AppIcon name="RefreshCw" :size="16" :stroke-width="2.3" aria-hidden="true" />
        </button>
      </header>

      <main class="os-payroll__content">
        <div class="os-payroll__controls">
          <div class="os-payroll__tabs" role="tablist" aria-label="Jenis data payroll">
            <button
              type="button"
              role="tab"
              :aria-selected="activeTab === 'payslip'"
              :class="{ 'os-payroll__tab--active': activeTab === 'payslip' }"
              @click="activeTab = 'payslip'"
            >
              Payslip
            </button>
            <button
              type="button"
              role="tab"
              :aria-selected="activeTab === 'incentive'"
              :class="{ 'os-payroll__tab--active': activeTab === 'incentive' }"
              @click="activeTab = 'incentive'"
            >
              Insentif
            </button>
          </div>
        </div>

        <section class="os-payroll__period" aria-label="Periode payroll">
          <button
            type="button"
            class="os-payroll__icon-btn os-payroll__period-nav"
            aria-label="Periode sebelumnya"
            :disabled="isLoading || selectedPeriodIndex < 0 || selectedPeriodIndex >= periods.length - 1"
            @click="navigatePeriod(1)"
          >
            <AppIcon name="ArrowLeft" :size="18" :stroke-width="2.4" aria-hidden="true" />
          </button>
          <div class="os-payroll__period-label">
            <span>Periode</span>
            <strong>{{ selectedPeriod ? formatPeriod(selectedPeriod) : '—' }}</strong>
          </div>
          <button
            type="button"
            class="os-payroll__icon-btn os-payroll__period-nav"
            aria-label="Periode berikutnya"
            :disabled="isLoading || selectedPeriodIndex <= 0"
            @click="navigatePeriod(-1)"
          >
            <AppIcon name="ChevronRight" :size="18" :stroke-width="2.4" aria-hidden="true" />
          </button>
        </section>

        <p v-if="errorMessage" class="os-payroll__message os-payroll__message--error" role="alert">
          {{ errorMessage }}
        </p>
        <p v-else-if="isLoading" class="os-payroll__message" role="status">Memuat data payroll…</p>
        <template v-else-if="activeTab === 'payslip'">
          <article v-for="item in visiblePayslips" :key="item.period" class="os-payroll__card">
            <div class="os-payroll__card-head">
              <div>
                <span>Slip gaji</span>
                <strong>{{ formatPeriod(item.period) }}</strong>
              </div>
              <small>{{ item.vendor || 'Outsource' }}</small>
            </div>
            <dl class="os-payroll__lines">
              <div>
                <dt>Hari Kerja Efektif</dt>
                <dd>{{ item.hke }}</dd>
              </div>
              <div>
                <dt>Gaji Pokok</dt>
                <dd>{{ formatCurrency(item.basic_salary) }}</dd>
              </div>
              <div>
                <dt>Potongan BPJS Kesehatan</dt>
                <dd>{{ formatCurrency(item.bpjs_kesehatan_deduction) }}</dd>
              </div>
              <div>
                <dt>Potongan Pinjaman</dt>
                <dd>{{ formatCurrency(item.loan_deduction) }}</dd>
              </div>
              <div class="os-payroll__total">
                <dt>Take Home Pay</dt>
                <dd>{{ formatCurrency(item.take_home_pay) }}</dd>
              </div>
            </dl>
          </article>
          <p v-if="visiblePayslips.length === 0" class="os-payroll__message">
            Belum ada data payslip untuk periode ini.
          </p>
        </template>
        <template v-else>
          <article v-for="item in visibleIncentives" :key="item.period" class="os-payroll__card">
            <div class="os-payroll__card-head">
              <div>
                <span>Insentif</span>
                <strong>{{ formatPeriod(item.period) }}</strong>
              </div>
              <small>{{ item.vendor || 'Outsource' }}</small>
            </div>
            <dl class="os-payroll__lines">
              <div>
                <dt>UMK</dt>
                <dd>{{ formatCurrency(item.umk_amount) }}</dd>
              </div>
              <div class="os-payroll__total">
                <dt>Insentif</dt>
                <dd>{{ formatCurrency(item.incentive_amount) }}</dd>
              </div>
            </dl>
          </article>
          <p v-if="visibleIncentives.length === 0" class="os-payroll__message">
            Belum ada data insentif untuk periode ini.
          </p>
        </template>
      </main>
    </div>
  </div>
</template>

<style scoped>
.os-payroll {
  position: fixed;
  inset: 0;
  z-index: 1200;
  overflow-y: auto;
  background: #f8f8f8;
  color: #18181b;
  text-align: left;
}

.os-payroll p {
  margin: 0;
}

.os-payroll__inner {
  box-sizing: border-box;
  width: min(100%, 32rem);
  margin: 0 auto;
  padding: 0.75rem 1rem 3rem;
}

.os-payroll__bar {
  position: sticky;
  top: 0;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.5rem 0 0.85rem;
  background: #f8f8f8;
}

.os-payroll__icon-btn {
  display: grid;
  flex-shrink: 0;
  width: 2.25rem;
  height: 2.25rem;
  place-items: center;
  padding: 0;
  border: 1px solid #e4e4e7;
  border-radius: 10px;
  background: #fff;
  color: #eb1c24;
  cursor: pointer;
}

.os-payroll__icon-btn:disabled {
  cursor: not-allowed;
  opacity: 0.45;
}

.os-payroll__title {
  flex: 1;
  min-width: 0;
}

.os-payroll__eyebrow {
  color: #eb1c24;
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.13em;
}

.os-payroll__title strong {
  display: block;
  overflow: hidden;
  font-size: 0.98rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.os-payroll__content {
  padding-top: 0.25rem;
}

.os-payroll__controls {
  margin-bottom: 1rem;
}

.os-payroll__tabs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  padding: 0.25rem;
  border: 1px solid #e4e4e7;
  border-radius: 0.75rem;
  background: #fff;
}

.os-payroll__tabs button {
  min-height: 2.35rem;
  border: 0;
  border-radius: 0.55rem;
  background: transparent;
  color: #52525b;
  font: inherit;
  font-size: 0.84rem;
  font-weight: 700;
  cursor: pointer;
}

.os-payroll__tabs .os-payroll__tab--active {
  background: #eb1c24;
  color: #fff;
}

.os-payroll__period {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.85rem;
  margin-bottom: 1rem;
  border-radius: 14px;
  background: linear-gradient(135deg, #eb1c24, #b5171d);
  box-shadow: 0 10px 24px rgba(235, 28, 36, 0.22);
}

.os-payroll__period-nav {
  border-color: rgba(255, 255, 255, 0.25);
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
}

.os-payroll__period-label {
  display: grid;
  flex: 1;
  gap: 0.15rem;
  color: #fff;
  text-align: center;
}

.os-payroll__period-label span {
  font-size: 0.66rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  opacity: 0.8;
}

.os-payroll__period-label strong {
  font-size: 0.98rem;
}

.os-payroll__card {
  margin-bottom: 0.85rem;
  padding: 0.95rem;
  border: 1px solid #e4e4e7;
  border-radius: 12px;
  background: #fff;
}

.os-payroll__card-head,
.os-payroll__lines > div {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
}

.os-payroll__card-head {
  align-items: center;
  margin-bottom: 0.7rem;
  padding-bottom: 0.65rem;
  border-bottom: 1px solid #f1f1f3;
}

.os-payroll__card-head > div {
  display: grid;
  gap: 0.15rem;
}

.os-payroll__card-head span {
  color: #eb1c24;
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.os-payroll__card-head strong {
  color: #18181b;
  font-size: 0.98rem;
}

.os-payroll__card-head small {
  color: #71717a;
  font-size: 0.72rem;
  text-align: right;
}

.os-payroll__lines {
  display: grid;
  gap: 0;
  margin: 0;
}

.os-payroll__lines > div {
  padding: 0.58rem 0;
  border-top: 1px solid #f1f1f3;
}

.os-payroll__lines dt {
  color: #52525b;
  font-size: 0.8rem;
}

.os-payroll__lines dd {
  margin: 0;
  color: #27272a;
  font-size: 0.82rem;
  font-weight: 650;
  font-variant-numeric: tabular-nums;
  text-align: right;
  white-space: nowrap;
}

.os-payroll__lines .os-payroll__total {
  margin-top: 0.2rem;
  border-top: 1px dashed #e4a2a5;
}

.os-payroll__total dt,
.os-payroll__total dd {
  color: #c4161c;
  font-weight: 800;
}

.os-payroll__total dd {
  font-size: 0.94rem;
}

.os-payroll__message {
  margin: 0;
  padding: 0.9rem;
  border: 1px solid #e4e4e7;
  border-radius: 10px;
  background: #fff;
  color: #52525b;
  font-size: 0.84rem;
  text-align: center;
}

.os-payroll__message--error {
  border-color: #fecaca;
  background: #fef2f2;
  color: #b91c1c;
}

@media (min-width: 600px) {
  .os-payroll__card {
    padding: 1.1rem;
  }
}
</style>
