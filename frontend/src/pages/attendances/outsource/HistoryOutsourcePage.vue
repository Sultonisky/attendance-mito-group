<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import AppIcon from "../../../components/AppIcon.vue";
import { ApiError } from "../../../services/apiClient";
import {
  fetchOutsourcePeriodHistory,
  type OutsourcePeriodHistory,
  type OutsourcePeriodHistoryItem,
  type OutsourcePeriodHistorySession,
} from "../../../services/outsourceService";
import {
  ATTENDANCE_TIMEZONE,
  formatAttendanceLongDate,
  formatAttendanceTime,
} from "../../../utils/attendanceDateTime";

/**
 * Monthly attendance history for the logged-in outsource person.
 * Period boundaries (e.g. 25 → 24) and cross-midnight attribution are decided by the API.
 */
defineProps<{
  outsourceName: string;
}>();

const emit = defineEmits<{
  close: [];
}>();

const periodHistory = ref<OutsourcePeriodHistory | null>(null);
const isLoading = ref(false);
const errorMessage = ref("");
const expandedDates = ref<Set<string>>(new Set());

const periodRangeFormatter = new Intl.DateTimeFormat("id-ID", {
  timeZone: ATTENDANCE_TIMEZONE,
  day: "numeric",
  month: "short",
  year: "numeric",
});

const shortDayFormatter = new Intl.DateTimeFormat("id-ID", {
  timeZone: ATTENDANCE_TIMEZONE,
  weekday: "short",
  day: "numeric",
  month: "short",
});

function businessDateToInstant(date: string): string {
  return `${date}T00:00:00+07:00`;
}

function formatPeriodRange(start: string, end: string): string {
  const from = periodRangeFormatter.format(new Date(businessDateToInstant(start)));
  const to = periodRangeFormatter.format(new Date(businessDateToInstant(end)));
  return `${from} – ${to}`;
}

function formatDay(date: string): string {
  return formatAttendanceLongDate(businessDateToInstant(date), "--");
}

function formatShortDay(date: string | null): string {
  if (!date) return "";
  return shortDayFormatter.format(new Date(businessDateToInstant(date)));
}

function formatTime(iso: string | null): string {
  return formatAttendanceTime(iso, "--:--");
}

function formatDurationLong(minutes: number | null | undefined): string {
  if (minutes === null || minutes === undefined || minutes <= 0) return "—";
  return `${Math.floor(minutes / 60)} Jam ${minutes % 60} Menit`;
}

function formatDayOffset(offset: number | null | undefined): string {
  return offset && offset > 0 ? `+${offset} hari` : "";
}

function isItemOpen(item: OutsourcePeriodHistoryItem): boolean {
  return item.attended && (item.has_open_session || item.status === "incomplete");
}

function formatStatus(item: OutsourcePeriodHistoryItem): string {
  if (!item.attended) return item.status === "pending" ? "Belum absen" : "Tidak hadir";
  if (isItemOpen(item)) return "Belum clock out";
  const normalized = item.status.trim().toLowerCase();
  if (normalized === "present" || normalized === "completed") return "Selesai";
  return item.status || "—";
}

function statusClass(item: OutsourcePeriodHistoryItem): string {
  if (!item.attended) {
    return item.status === "pending" ? "os-history__status--pending" : "os-history__status--absent";
  }
  return isItemOpen(item) ? "os-history__status--open" : "os-history__status--ok";
}

function sessionLegs(session: OutsourcePeriodHistorySession) {
  return [
    {
      key: "in",
      label: "Clock in",
      at: session.check_in_at,
      date: session.check_in_date,
      offset: 0,
      location: session.check_in_location,
    },
    {
      key: "out",
      label: "Clock out",
      at: session.check_out_at,
      date: session.check_out_date,
      offset: session.check_out_day_offset,
      location: session.check_out_location,
    },
  ];
}

function isExpanded(item: OutsourcePeriodHistoryItem): boolean {
  return item.attended && expandedDates.value.has(item.attendance_date);
}

function toggleItem(item: OutsourcePeriodHistoryItem): void {
  if (!item.attended) return;
  const next = new Set(expandedDates.value);
  if (next.has(item.attendance_date)) next.delete(item.attendance_date);
  else next.add(item.attendance_date);
  expandedDates.value = next;
}

/** Newest attended day open by default; a refresh of the same period keeps the user's choice. */
function syncExpanded(previousKey: string | null, history: OutsourcePeriodHistory): void {
  const attendedDates = new Set(
    history.items.filter((item) => item.attended).map((item) => item.attendance_date),
  );
  if (previousKey === history.period.key) {
    expandedDates.value = new Set([...expandedDates.value].filter((date) => attendedDates.has(date)));
    return;
  }
  const newest = history.items.find((item) => item.attended);
  expandedDates.value = newest ? new Set([newest.attendance_date]) : new Set();
}

async function loadPeriod(period?: string | null): Promise<void> {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    const previousKey = periodHistory.value?.period.key ?? null;
    const history = await fetchOutsourcePeriodHistory(period);
    syncExpanded(previousKey, history);
    periodHistory.value = history;
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 401) {
      errorMessage.value = "Sesi telah berakhir. Silakan login kembali untuk melihat riwayat.";
    } else if (e instanceof ApiError && e.status === 422) {
      errorMessage.value = "Periode tidak valid.";
    } else {
      errorMessage.value = "Gagal memuat riwayat. Periksa koneksi lalu coba lagi.";
    }
  } finally {
    isLoading.value = false;
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === "Escape") emit("close");
}

onMounted(() => {
  window.addEventListener("keydown", onKeydown);
  void loadPeriod();
});

onUnmounted(() => {
  window.removeEventListener("keydown", onKeydown);
});
</script>

<template>
  <div class="os-history" role="dialog" aria-modal="true" aria-label="Riwayat absensi">
    <div class="os-history__inner">
      <header class="os-history__bar">
        <button type="button" class="os-history__icon-btn" aria-label="Kembali" @click="emit('close')">
          <AppIcon name="ArrowLeft" :size="18" :stroke-width="2.3" aria-hidden="true" />
        </button>
        <div class="os-history__title">
          <p class="os-history__eyebrow">RIWAYAT ABSENSI</p>
          <strong>{{ outsourceName || "Personel" }}</strong>
        </div>
        <button
          type="button"
          class="os-history__icon-btn"
          aria-label="Muat ulang"
          :disabled="isLoading"
          @click="loadPeriod(periodHistory?.period.key ?? null)"
        >
          <AppIcon name="RefreshCw" :size="16" :stroke-width="2.3" aria-hidden="true" />
        </button>
      </header>

      <section class="os-history__period" aria-label="Periode">
        <button
          type="button"
          class="os-history__icon-btn os-history__nav"
          aria-label="Periode sebelumnya"
          :disabled="isLoading || !periodHistory?.period.previous_key"
          @click="loadPeriod(periodHistory?.period.previous_key)"
        >
          <AppIcon name="ChevronLeft" :size="18" :stroke-width="2.4" aria-hidden="true" />
        </button>
        <div class="os-history__period-label">
          <span>Periode</span>
          <strong v-if="periodHistory">
            {{ formatPeriodRange(periodHistory.period.start_date, periodHistory.period.end_date) }}
          </strong>
          <strong v-else>—</strong>
          <em v-if="periodHistory?.period.is_current">Periode berjalan</em>
        </div>
        <button
          type="button"
          class="os-history__icon-btn os-history__nav"
          aria-label="Periode berikutnya"
          :disabled="isLoading || !periodHistory?.period.next_key"
          @click="loadPeriod(periodHistory?.period.next_key)"
        >
          <AppIcon name="ChevronRight" :size="18" :stroke-width="2.4" aria-hidden="true" />
        </button>
      </section>

      <p v-if="errorMessage" class="os-history__error" role="alert">{{ errorMessage }}</p>

      <template v-if="periodHistory">
        <section class="os-history__summary" aria-label="Ringkasan periode">
          <div class="os-history__stat">
            <span>Hari Hadir</span>
            <strong>{{ periodHistory.summary.days_attended }} hari</strong>
          </div>
          <div class="os-history__stat">
            <span>Tidak Hadir</span>
            <strong>{{ periodHistory.summary.days_absent }} hari</strong>
          </div>
          <div class="os-history__stat">
            <span>Selesai</span>
            <strong>{{ periodHistory.summary.days_complete }} hari</strong>
          </div>
          <div class="os-history__stat">
            <span>Belum Clock Out</span>
            <strong>{{ periodHistory.summary.days_incomplete }} hari</strong>
          </div>
          <div class="os-history__stat os-history__stat--footer">
            <span>Rata-rata / Hari</span>
            <strong>{{ formatDurationLong(periodHistory.summary.average_duration_minutes) }}</strong>
          </div>
          <div class="os-history__stat os-history__stat--footer">
            <span>Total Durasi Bekerja</span>
            <strong class="os-history__total">
              {{ formatDurationLong(periodHistory.summary.total_duration_minutes) }}
            </strong>
          </div>
        </section>

        <p v-if="periodHistory.items.length === 0 && !isLoading" class="os-history__empty">
          Belum ada absensi pada periode ini.
        </p>

        <ul v-else class="os-history__list">
          <li
            v-for="item in periodHistory.items"
            :key="item.attendance_date"
            class="os-history__day"
            :class="{
              'os-history__day--open': isExpanded(item),
              'os-history__day--missing': !item.attended,
            }"
          >
            <div v-if="!item.attended" class="os-history__day-toggle os-history__day-toggle--static">
              <span class="os-history__day-main">
                <strong>{{ formatDay(item.attendance_date) }}</strong>
              </span>
              <span class="os-history__day-meta">
                <span class="os-history__status" :class="statusClass(item)">
                  {{ formatStatus(item) }}
                </span>
              </span>
            </div>

            <button
              v-else
              type="button"
              class="os-history__day-toggle"
              :aria-expanded="isExpanded(item)"
              :aria-controls="`os-history-day-${item.attendance_date}`"
              @click="toggleItem(item)"
            >
              <span class="os-history__day-main">
                <strong>{{ formatDay(item.attendance_date) }}</strong>
                <small v-if="!isExpanded(item)" class="os-history__day-brief">
                  {{ formatTime(item.check_in_at) }} – {{ formatTime(item.check_out_at) }}
                  <span v-if="formatDayOffset(item.check_out_day_offset)" class="os-history__offset">
                    {{ formatDayOffset(item.check_out_day_offset) }}
                  </span>
                  · {{ formatDurationLong(item.duration_minutes) }}
                </small>
              </span>
              <span class="os-history__day-meta">
                <span class="os-history__status" :class="statusClass(item)">
                  {{ formatStatus(item) }}
                </span>
                <AppIcon
                  :name="isExpanded(item) ? 'ChevronUp' : 'ChevronDown'"
                  :size="18"
                  :stroke-width="2.2"
                  aria-hidden="true"
                />
              </span>
            </button>

            <div
              v-if="item.attended"
              v-show="isExpanded(item)"
              :id="`os-history-day-${item.attendance_date}`"
              class="os-history__day-body"
            >
              <div class="os-history__times">
                <div class="os-history__stat">
                  <span>Jam Masuk</span>
                  <strong>{{ formatTime(item.check_in_at) }}</strong>
                </div>
                <div class="os-history__stat">
                  <span>Jam Keluar</span>
                  <strong>
                    {{ formatTime(item.check_out_at) }}
                    <sup v-if="formatDayOffset(item.check_out_day_offset)" class="os-history__offset">
                      {{ formatDayOffset(item.check_out_day_offset) }}
                    </sup>
                  </strong>
                  <small v-if="formatDayOffset(item.check_out_day_offset)">
                    {{ formatShortDay(item.check_out_date) }}
                  </small>
                </div>
                <div class="os-history__stat">
                  <span>Durasi</span>
                  <strong>{{ formatDurationLong(item.duration_minutes) }}</strong>
                </div>
              </div>

              <div v-for="(session, index) in item.sessions" :key="index" class="os-history__session">
                <div v-if="item.sessions.length > 1" class="os-history__session-head">
                  <span class="os-history__session-no">
                    Sesi {{ index + 1 }} · {{ formatDurationLong(session.duration_minutes) }}
                  </span>
                </div>

                <div
                  v-for="leg in sessionLegs(session)"
                  :key="leg.key"
                  class="os-history__leg"
                  :class="`os-history__leg--${leg.key}`"
                >
                  <span class="os-history__leg-dot" aria-hidden="true" />
                  <div class="os-history__leg-body">
                    <p class="os-history__leg-time">
                      <strong>{{ leg.label }}</strong>
                      <template v-if="leg.at">
                        {{ formatShortDay(leg.date) }}, {{ formatTime(leg.at) }}
                        <span v-if="formatDayOffset(leg.offset)" class="os-history__offset">
                          {{ formatDayOffset(leg.offset) }}
                        </span>
                      </template>
                      <em v-else>Belum clock out</em>
                    </p>
                    <template v-if="leg.location">
                      <p class="os-history__leg-pin">
                        <AppIcon name="MapPinned" :size="12" :stroke-width="2.2" aria-hidden="true" />
                        {{ leg.location.pin_name ?? "Pin tidak tercatat" }}
                      </p>
                      <p v-if="leg.location.pin_address" class="os-history__leg-meta">
                        {{ leg.location.pin_address }}
                      </p>
                    </template>
                    <p v-else-if="leg.at" class="os-history__leg-meta">Lokasi tidak tercatat</p>
                  </div>
                </div>
              </div>
            </div>
          </li>
        </ul>
      </template>

      <p v-else-if="isLoading" class="os-history__empty">Memuat riwayat…</p>
    </div>
  </div>
</template>

<style scoped>
.os-history {
  --os-border: var(--border, #e4e4e7);
  --os-accent: var(--accent, #eb1c24);
  position: fixed;
  inset: 0;
  z-index: 1200;
  overflow-y: auto;
  background: #f8f8f8;
  color: var(--text-h);
  text-align: left;
}

.os-history p {
  margin: 0;
}

.os-history__inner {
  box-sizing: border-box;
  width: min(100%, 32rem);
  margin: 0 auto;
  padding: 0.75rem 1rem 3rem;
}

.os-history__bar {
  position: sticky;
  top: 0;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.5rem 0 0.85rem;
  background: #f8f8f8;
}

.os-history__title {
  flex: 1;
  min-width: 0;
}

.os-history__eyebrow {
  color: var(--os-accent);
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.13em;
}

.os-history__title strong {
  display: block;
  overflow: hidden;
  font-size: 0.98rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.os-history__icon-btn {
  display: grid;
  flex-shrink: 0;
  width: 2.25rem;
  height: 2.25rem;
  place-items: center;
  padding: 0;
  border-radius: 10px;
  border: 1px solid var(--os-border);
  background: #fff;
  color: var(--mito-red);
  cursor: pointer;
}

.os-history__icon-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.os-history__period {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.85rem;
  margin-bottom: 1rem;
  border-radius: 14px;
  background: linear-gradient(135deg, #eb1c24, #b5171d);
  box-shadow: 0 10px 24px rgba(235, 28, 36, 0.22);
}

.os-history__nav {
  border-color: rgba(255, 255, 255, 0.25);
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
}

.os-history__period-label {
  display: grid;
  flex: 1;
  gap: 0.15rem;
  text-align: center;
  color: #fff;
}

.os-history__period-label span {
  font-size: 0.66rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  opacity: 0.8;
}

.os-history__period-label strong {
  font-size: 0.98rem;
}

.os-history__period-label em {
  justify-self: center;
  padding: 0.1rem 0.5rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.2);
  font-size: 0.64rem;
  font-style: normal;
  font-weight: 700;
}

.os-history__error {
  margin-bottom: 1rem !important;
  padding: 0.7rem 0.85rem;
  border-radius: 10px;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 0.82rem;
}

.os-history__summary {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.85rem;
  padding: 1rem;
  margin-bottom: 1rem;
  border: 1px solid var(--os-border);
  border-radius: 12px;
  background: #fff;
}

.os-history__stat {
  display: grid;
  gap: 0.2rem;
  align-content: start;
}

.os-history__stat span {
  font-size: 0.7rem;
  color: var(--text);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.os-history__stat strong {
  font-size: 0.93rem;
  color: var(--text-h);
}

.os-history__stat small {
  font-size: 0.7rem;
  color: var(--text);
}

.os-history__stat--footer {
  padding-top: 0.6rem;
  border-top: 1px solid var(--os-border);
}

.os-history__total {
  color: var(--os-accent) !important;
  font-size: 1.1rem !important;
  font-weight: 700;
}

.os-history__empty {
  padding: 0.85rem 0.2rem 0.4rem;
  font-size: 0.82rem;
  color: var(--text);
  text-align: center;
}

.os-history__list {
  display: grid;
  gap: 0.65rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.os-history__day {
  overflow: hidden;
  border-radius: 12px;
  border: 1px solid var(--os-border);
  background: #fff;
  transition: box-shadow 160ms ease;
}

.os-history__day--open {
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
}

.os-history__day-toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.65rem;
  width: 100%;
  margin: 0;
  padding: 0.8rem 0.9rem;
  border: 0;
  background: #fff;
  color: var(--text-h);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.os-history__day-toggle:focus-visible {
  outline: 3px solid rgba(235, 28, 36, 0.18);
  outline-offset: -3px;
}

.os-history__day--open .os-history__day-toggle {
  border-bottom: 1px solid var(--os-border);
}

.os-history__day--missing,
.os-history__day--missing .os-history__day-toggle {
  background: #fafafa;
}

.os-history__day-toggle--static {
  cursor: default;
}

.os-history__day--missing .os-history__day-main strong {
  color: var(--text);
  font-weight: 600;
}

.os-history__day-main {
  display: grid;
  gap: 0.2rem;
  min-width: 0;
}

.os-history__day-main strong {
  font-size: 0.88rem;
}

.os-history__day-brief {
  overflow: hidden;
  font-size: 0.74rem;
  color: var(--text);
  white-space: nowrap;
  text-overflow: ellipsis;
}

.os-history__day-meta {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  flex-shrink: 0;
  color: var(--text);
}

.os-history__day-body {
  padding: 0.75rem 0.9rem 0.85rem;
  animation: os-history-day-open 180ms ease-out;
}

@keyframes os-history-day-open {
  from {
    opacity: 0;
    transform: translateY(-4px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .os-history__day-body {
    animation: none;
  }
}

.os-history__status {
  display: inline-flex;
  align-items: center;
  min-height: 1.4rem;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  font-size: 0.66rem;
  font-weight: 700;
  white-space: nowrap;
}

.os-history__status--ok {
  background: #ecfdf5;
  color: #15803d;
}

.os-history__status--open {
  background: #fff7ed;
  color: #c2410c;
}

.os-history__status--absent {
  background: #fef2f2;
  color: #b91c1c;
}

.os-history__status--pending {
  background: #f4f4f5;
  color: #52525b;
}

.os-history__times {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
  padding: 0.65rem;
  border-radius: 10px;
  background: #fafafa;
}

.os-history__times .os-history__stat strong {
  font-size: 0.86rem;
}

.os-history__offset {
  display: inline-block;
  margin-left: 0.2rem;
  padding: 0.05rem 0.35rem;
  border-radius: 999px;
  background: #eef2ff;
  color: #4338ca;
  font-size: 0.62rem;
  font-weight: 700;
  vertical-align: middle;
}

.os-history__session {
  margin-top: 0.7rem;
  padding-top: 0.65rem;
  border-top: 1px dashed var(--os-border);
}

.os-history__session-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.35rem;
  margin-bottom: 0.5rem;
}

.os-history__session-no {
  font-size: 0.74rem;
  font-weight: 700;
  color: var(--text-h);
}

.os-history__leg {
  position: relative;
  display: flex;
  gap: 0.6rem;
  padding-bottom: 0.6rem;
}

.os-history__leg--in::after {
  content: "";
  position: absolute;
  top: 0.9rem;
  bottom: 0;
  left: 0.3rem;
  width: 2px;
  background: #e4e4e7;
}

.os-history__leg--out {
  padding-bottom: 0;
}

.os-history__leg-dot {
  flex-shrink: 0;
  width: 0.62rem;
  height: 0.62rem;
  margin-top: 0.25rem;
  border-radius: 50%;
  background: #16a34a;
  box-shadow: 0 0 0 3px #dcfce7;
}

.os-history__leg--out .os-history__leg-dot {
  background: var(--os-accent);
  box-shadow: 0 0 0 3px #fee2e2;
}

.os-history__leg-body {
  display: grid;
  flex: 1;
  gap: 0.15rem;
  min-width: 0;
}

.os-history__leg-time {
  font-size: 0.78rem;
  color: var(--text-h);
}

.os-history__leg-time strong {
  margin-right: 0.3rem;
}

.os-history__leg-time em {
  color: #c2410c;
  font-style: normal;
  font-weight: 600;
}

.os-history__leg-pin {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  font-size: 0.76rem;
  font-weight: 600;
  color: var(--text-h);
}

.os-history__leg-meta {
  font-size: 0.7rem;
  line-height: 1.4;
  color: var(--text);
  overflow-wrap: anywhere;
}
</style>
