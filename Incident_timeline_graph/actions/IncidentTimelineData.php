<?php

declare(strict_types=1);

namespace Modules\IncidentTimeline\Actions;

use API;
use CController;
use CControllerResponseData;

final class IncidentTimelineData extends CController {
	private const MAX_RANGE_SECONDS = 5356800; // 62 days (generous single-month cap).
	private const FETCH_BATCH = 2000;
	private const MAX_EVENTS = 100000;
	private const RECOVERY_BATCH = 500;

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'time_from' => 'required|string',
			'time_to' => 'required|string'
		];

		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		try {
			set_time_limit(300);

			$time_from = (int) $this->getInput('time_from');
			$time_to = (int) $this->getInput('time_to');

			if ($time_from <= 0 || $time_to <= 0 || $time_from > $time_to) {
				$this->respondJsonError(_('Invalid time range.'), 400);
				return;
			}

			if (($time_to - $time_from) > self::MAX_RANGE_SECONDS) {
				$this->respondJsonError(_('The selected time range is too large.'), 400);
				return;
			}

			// Build empty daily buckets for the full range.
			$daily_counts = $this->buildEmptyDailyBuckets($time_from, $time_to);
			$severity_counts = array_fill(0, 6, 0);
			$incidents_csv = [];
			$recovery_ids = [];
			$total = 0;
			$max_reached = false;

			// Fetch events in small sequential batches and aggregate immediately.
			$cursor_from = $time_from;
			$last_eventid = '0';
			$seen = [];

			while ($total < self::MAX_EVENTS) {
				$batch = API::Event()->get([
					'output' => ['eventid', 'objectid', 'clock', 'name', 'severity', 'r_eventid'],
					'source' => 0,
					'object' => 0,
					'value' => 1,
					'time_from' => $cursor_from,
					'time_till' => $time_to,
					'sortfield' => ['clock', 'eventid'],
					'sortorder' => 'ASC',
					'limit' => self::FETCH_BATCH,
					'preservekeys' => true
				]);

				if ($batch === false) {
					$this->respondJsonError(_('Failed to retrieve events from the API.'), 500);
					return;
				}

				if ($batch === []) {
					break;
				}

				foreach ($batch as $eventid => $event) {
					$eid = (string) $eventid;

					if (isset($seen[$eid])) {
						continue;
					}

					$seen[$eid] = true;

					$clock = (int) $event['clock'];
					$severity = (int) $event['severity'];
					$day_key = gmdate('Y-m-d', $clock);
					$r_eventid = (string) ($event['r_eventid'] ?? '0');

					// Aggregate into daily counts.
					if (isset($daily_counts[$day_key]) && $severity >= 0 && $severity <= 5) {
						$daily_counts[$day_key]['sev_'.$severity]++;
					}

					// Aggregate severity totals.
					if ($severity >= 0 && $severity <= 5) {
						$severity_counts[$severity]++;
					}

					// Collect recovery IDs.
					if ($r_eventid !== '0' && $r_eventid !== '') {
						$recovery_ids[$r_eventid] = true;
					}

					// Keep minimal data for CSV export.
					$incidents_csv[] = [
						'eid' => $eid,
						'oid' => (string) $event['objectid'],
						'n' => (string) $event['name'],
						's' => $severity,
						'c' => $clock,
						'r' => $r_eventid
					];

					$total++;

					if ($total >= self::MAX_EVENTS) {
						$max_reached = true;
						break 2;
					}
				}

				if (count($batch) < self::FETCH_BATCH) {
					break;
				}

				$last_event = end($batch);
				$new_cursor = (int) $last_event['clock'];

				if ($new_cursor <= $cursor_from && $last_eventid === (string) $last_event['eventid']) {
					$cursor_from = $new_cursor + 1;
				}
				else {
					$cursor_from = $new_cursor;
				}

				$last_eventid = (string) $last_event['eventid'];

				// Free the batch immediately.
				unset($batch);
			}

			// Free the dedup set.
			unset($seen);

			// Fetch recovery clocks in small batches.
			$recovery_ids = array_keys($recovery_ids);
			$recovery_clock_map = [];

			foreach (array_chunk($recovery_ids, self::RECOVERY_BATCH) as $chunk) {
				$recovery_events = API::Event()->get([
					'output' => ['eventid', 'clock'],
					'eventids' => $chunk,
					'preservekeys' => true
				]);

				if (is_array($recovery_events)) {
					foreach ($recovery_events as $re) {
						$recovery_clock_map[(string) $re['eventid']] = (int) $re['clock'];
					}
				}

				unset($recovery_events);
			}

			unset($recovery_ids);

			// Attach recovery clocks to CSV incidents.
			foreach ($incidents_csv as &$inc) {
				$r = $inc['r'];
				$inc['rc'] = ($r !== '0' && $r !== '') ? ($recovery_clock_map[$r] ?? 0) : 0;
			}
			unset($inc, $recovery_clock_map);

			// Build severity summary.
			$severity_map = [
				TRIGGER_SEVERITY_NOT_CLASSIFIED => _('Not classified'),
				TRIGGER_SEVERITY_INFORMATION => _('Information'),
				TRIGGER_SEVERITY_WARNING => _('Warning'),
				TRIGGER_SEVERITY_AVERAGE => _('Average'),
				TRIGGER_SEVERITY_HIGH => _('High'),
				TRIGGER_SEVERITY_DISASTER => _('Disaster')
			];

			$severity_summary = [];

			foreach ($severity_map as $severity => $label) {
				$severity_summary[] = [
					'severity' => $severity,
					'label' => $label,
					'count' => $severity_counts[$severity] ?? 0
				];
			}

			// Convert daily_counts map to ordered array.
			$daily_data = array_values($daily_counts);
			unset($daily_counts);

			$this->respondJson([
				'daily_data' => $daily_data,
				'severity_summary' => $severity_summary,
				'incidents' => $incidents_csv,
				'meta' => [
					'time_from' => $time_from,
					'time_to' => $time_to,
					'generated_at' => time(),
					'limit' => self::MAX_EVENTS,
					'limit_reached' => $max_reached,
					'total_incidents' => $total
				]
			]);
		}
		catch (\Throwable $e) {
			$this->respondJsonError($e->getMessage(), 500);
		}
	}

	/**
	 * Build empty daily buckets covering the full time range so every calendar day
	 * is present even if it has zero events.
	 */
	private function buildEmptyDailyBuckets(int $time_from, int $time_to): array {
		$buckets = [];
		$cursor = strtotime(gmdate('Y-m-d', $time_from));
		$end = strtotime(gmdate('Y-m-d', $time_to));

		while ($cursor <= $end) {
			$key = gmdate('Y-m-d', $cursor);
			$buckets[$key] = [
				'date' => $key,
				'sev_0' => 0,
				'sev_1' => 0,
				'sev_2' => 0,
				'sev_3' => 0,
				'sev_4' => 0,
				'sev_5' => 0
			];
			$cursor += 86400;
		}

		return $buckets;
	}

	private function respondJson(array $payload): void {
		$json = json_encode(
			$payload,
			JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ($json === false) {
			$json = '{"error":{"code":500,"message":"Failed to encode JSON response."}}';
		}

		$this->setResponse(new CControllerResponseData([
			'main_block' => $json
		]));
	}

	private function respondJsonError(string $message, int $code): void {
		$this->respondJson([
			'error' => [
				'code' => $code,
				'message' => $message
			]
		]);
	}
}
