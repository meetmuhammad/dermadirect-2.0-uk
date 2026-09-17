<?php

declare(strict_types=1);

namespace App\DermadirectToolsMenu\NextDayDeliveryTimer;

use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Vite;

defined('ABSPATH') || exit;

class NextDayDeliveryTimerManager
{
	public const OPTION_PAGE_SLUG = 'next-day-delivery-timer';
	public const FIELD_BANNER_ENABLED = 'nddt_banner_enabled';
	public const FIELD_FALLBACK_ENABLED = 'nddt_fallback_enabled';
	public const FIELD_FALLBACK_MESSAGE = 'nddt_fallback_message';
	public const FIELD_CUTOFF_TIME = 'nddt_cutoff_time';
	public const FIELD_HOLIDAY_RANGES = 'nddt_holiday_ranges';
	public const FIELD_ACTIVE_WEEKDAYS = 'nddt_active_weekdays';
	public const FIELD_REQUIRE_IN_STOCK = 'nddt_require_in_stock';
	public const FIELD_BORDER_COLOR = 'nddt_border_color';
	public const FIELD_COUNTDOWN_COLOR = 'nddt_countdown_color';

	private const SHORTCODE = 'next_day_delivery_banner';
	private const TIMEZONE = 'Europe/London';

	public static function init(): void
	{
		add_shortcode(self::SHORTCODE, [self::class, 'renderShortcode']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
	}

	public static function enqueueFrontendAssets(): void
	{
		self::enqueueViteAsset('resources/css/next-day-delivery-timer.css', 'next-day-delivery-timer-css');
		self::enqueueViteAsset('resources/js/next-day-delivery-timer.js', 'next-day-delivery-timer-js');
	}

	public static function enqueueAdminAssets(): void
	{
		if (!is_admin() || self::getCurrentAdminPageSlug() !== self::OPTION_PAGE_SLUG) {
			return;
		}

		self::enqueueViteAsset('resources/css/admin/next-day-delivery-timer.css', 'next-day-delivery-timer-admin-css');
		self::enqueueViteAsset('resources/js/admin/next-day-delivery-timer.js', 'next-day-delivery-timer-admin-js');
	}

	public static function renderShortcode(array $atts = []): string
	{
		unset($atts);

		return self::getBannerMarkup();
	}

	public static function getBannerMarkup(): string
	{
		$state = self::buildBannerState();

		if (empty($state['should_render'])) {
			return '';
		}

		ob_start();
		?>
		<div
			class="nddt-banner js-next-day-delivery-timer"
			style="--nddt-border-color: <?php echo esc_attr($state['border_color']); ?>; --nddt-countdown-color: <?php echo esc_attr($state['countdown_color']); ?>;"
			data-expiry-action="<?php echo esc_attr($state['expiry_action']); ?>"
			data-fallback-html="<?php echo esc_attr(wp_json_encode($state['fallback_html'])); ?>"
			<?php if (!empty($state['deadline_ts'])) : ?>data-deadline="<?php echo esc_attr((string) $state['deadline_ts']); ?>"<?php endif; ?>
		>
			<div class="nddt-banner__content" data-banner-content>
				<?php if (!empty($state['show_countdown'])) : ?>
					<span class="nddt-banner__prefix"><?php echo esc_html__('Order within', 'derma-direct'); ?></span>
					<strong class="nddt-banner__countdown" data-countdown><?php echo esc_html($state['initial_countdown']); ?></strong>
					<span class="nddt-banner__suffix"><?php echo esc_html__('for next-day delivery.', 'derma-direct'); ?></span>
				<?php else : ?>
					<div class="nddt-banner__fallback"><?php echo wp_kses_post($state['fallback_html']); ?></div>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	public static function getWeekdayChoices(): array
	{
		return [
			'1' => __('Monday', 'derma-direct'),
			'2' => __('Tuesday', 'derma-direct'),
			'3' => __('Wednesday', 'derma-direct'),
			'4' => __('Thursday', 'derma-direct'),
			'5' => __('Friday', 'derma-direct'),
			'6' => __('Saturday', 'derma-direct'),
			'7' => __('Sunday', 'derma-direct'),
		];
	}

	private static function buildBannerState(): array
	{
		$settings = self::getSettings();

		if (empty($settings['banner_enabled'])) {
			return ['should_render' => false];
		}

		$now = new DateTime('now', new DateTimeZone(self::TIMEZONE));
		$show_fallback = !empty($settings['fallback_enabled']);
		$fallback_html = self::getRenderedFallbackMessage($settings['fallback_message']);

		$show_countdown = false;
		$deadline_ts = 0;

		if (!self::isWithinHolidayWindow($now, $settings['holiday_ranges']) && in_array((int) $now->format('N'), $settings['active_weekdays'], true)) {
			$cutoff = clone $now;
			$cutoff->setTime($settings['cutoff_hour'], $settings['cutoff_minute'], 0);
			$cutoff_ts = $cutoff->getTimestamp();

			if ($now->getTimestamp() < $cutoff_ts && self::isCurrentProductEligible($settings)) {
				$show_countdown = true;
				$deadline_ts = $cutoff_ts;

				$holiday_start_ts = self::getNextHolidayStartTimestamp($now, $settings['holiday_ranges']);
				if ($holiday_start_ts !== null && $holiday_start_ts < $deadline_ts) {
					$deadline_ts = $holiday_start_ts;
				}
			}
		}

		if (!$show_countdown && !$show_fallback) {
			return ['should_render' => false];
		}

		return [
			'should_render' => true,
			'show_countdown' => $show_countdown,
			'deadline_ts' => $deadline_ts,
			'expiry_action' => $show_fallback ? 'fallback' : 'hide',
			'fallback_html' => $fallback_html,
			'initial_countdown' => $show_countdown ? self::formatCountdown(max(0, $deadline_ts - $now->getTimestamp())) : '',
			'border_color' => $settings['border_color'],
			'countdown_color' => $settings['countdown_color'],
		];
	}

	private static function getSettings(): array
	{
		$defaults = self::getDefaultSettings();

		if (!function_exists('get_field')) {
			$defaults['banner_enabled'] = false;

			return $defaults;
		}

		$cutoff_time = get_field(self::FIELD_CUTOFF_TIME, 'option');
		$holiday_ranges = get_field(self::FIELD_HOLIDAY_RANGES, 'option');

		return [
			'banner_enabled' => (bool) (get_field(self::FIELD_BANNER_ENABLED, 'option') ?? $defaults['banner_enabled']),
			'fallback_enabled' => (bool) (get_field(self::FIELD_FALLBACK_ENABLED, 'option') ?? $defaults['fallback_enabled']),
			'fallback_message' => (string) (get_field(self::FIELD_FALLBACK_MESSAGE, 'option') ?: $defaults['fallback_message']),
			'cutoff_hour' => self::normalizeHour($cutoff_time['hour'] ?? $defaults['cutoff_hour']),
			'cutoff_minute' => self::normalizeMinute($cutoff_time['minute'] ?? $defaults['cutoff_minute']),
			'holiday_ranges' => self::normalizeHolidayRanges(is_array($holiday_ranges) ? $holiday_ranges : []),
			'active_weekdays' => self::normalizeWeekdays(get_field(self::FIELD_ACTIVE_WEEKDAYS, 'option')),
			'require_in_stock' => (bool) (get_field(self::FIELD_REQUIRE_IN_STOCK, 'option') ?? $defaults['require_in_stock']),
			'border_color' => sanitize_hex_color((string) get_field(self::FIELD_BORDER_COLOR, 'option')) ?: $defaults['border_color'],
			'countdown_color' => sanitize_hex_color((string) get_field(self::FIELD_COUNTDOWN_COLOR, 'option')) ?: $defaults['countdown_color'],
		];
	}

	private static function normalizeWeekdays($raw_weekdays): array
	{
		$weekdays = array_filter(
			array_map('intval', is_array($raw_weekdays) ? $raw_weekdays : []),
			static fn (int $weekday): bool => $weekday >= 1 && $weekday <= 7
		);

		return $weekdays ? array_values(array_unique($weekdays)) : self::getDefaultSettings()['active_weekdays'];
	}

	private static function normalizeHolidayRanges(array $rows): array
	{
		$normalized = [];

		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$date = isset($row['date']) ? sanitize_text_field((string) $row['date']) : '';
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
				continue;
			}

			$start_hour = self::normalizeHour($row['start_hour'] ?? 0);
			$start_minute = self::normalizeMinute($row['start_minute'] ?? 0);
			$end_hour = self::normalizeHour($row['end_hour'] ?? 23);
			$end_minute = self::normalizeMinute($row['end_minute'] ?? 59);

			if (($end_hour * 60 + $end_minute) <= ($start_hour * 60 + $start_minute)) {
				continue;
			}

			$normalized[] = [
				'date' => $date,
				'start_hour' => $start_hour,
				'start_minute' => $start_minute,
				'end_hour' => $end_hour,
				'end_minute' => $end_minute,
			];
		}

		return $normalized;
	}

	private static function normalizeHour($value): int
	{
		return max(0, min(23, (int) $value));
	}

	private static function normalizeMinute($value): int
	{
		return max(0, min(59, (int) $value));
	}

	private static function isCurrentProductEligible(array $settings): bool
	{
		if (empty($settings['require_in_stock'])) {
			return true;
		}

		if (!class_exists('WC_Product') || !function_exists('wc_get_product')) {
			return false;
		}

		$product = wc_get_product(get_the_ID());

		return $product instanceof \WC_Product && $product->is_in_stock();
	}

	private static function isWithinHolidayWindow(DateTime $now, array $holiday_ranges): bool
	{
		$current_date = $now->format('Y-m-d');
		$current_ts = $now->getTimestamp();

		foreach ($holiday_ranges as $range) {
			if (($range['date'] ?? '') !== $current_date) {
				continue;
			}

			$start_ts = self::buildTimestamp($range['date'], (int) $range['start_hour'], (int) $range['start_minute']);
			$end_ts = self::buildTimestamp($range['date'], (int) $range['end_hour'], (int) $range['end_minute']);

			if ($current_ts >= $start_ts && $current_ts < $end_ts) {
				return true;
			}
		}

		return false;
	}

	private static function getNextHolidayStartTimestamp(DateTime $now, array $holiday_ranges): ?int
	{
		$current_date = $now->format('Y-m-d');
		$current_ts = $now->getTimestamp();
		$next_start_ts = null;

		foreach ($holiday_ranges as $range) {
			if (($range['date'] ?? '') !== $current_date) {
				continue;
			}

			$start_ts = self::buildTimestamp($range['date'], (int) $range['start_hour'], (int) $range['start_minute']);

			if ($start_ts <= $current_ts) {
				continue;
			}

			if ($next_start_ts === null || $start_ts < $next_start_ts) {
				$next_start_ts = $start_ts;
			}
		}

		return $next_start_ts;
	}

	private static function buildTimestamp(string $date, int $hour, int $minute): int
	{
		$datetime = new DateTime(sprintf('%s %02d:%02d:00', $date, $hour, $minute), new DateTimeZone(self::TIMEZONE));

		return $datetime->getTimestamp();
	}

	private static function getRenderedFallbackMessage(string $message): string
	{
		$message = trim(wp_kses_post($message));

		if ($message === '') {
			$message = self::getDefaultSettings()['fallback_message'];
		}

		return wpautop($message);
	}

	private static function formatCountdown(int $seconds): string
	{
		$hours = (int) floor($seconds / 3600);
		$minutes = (int) floor(($seconds % 3600) / 60);
		$seconds = $seconds % 60;

		return sprintf('%02dhrs %02dmins %02dsec', $hours, $minutes, $seconds);
	}

	private static function getDefaultSettings(): array
	{
		return [
			'banner_enabled' => true,
			'fallback_enabled' => true,
			'fallback_message' => 'To get next day delivery, place your order on Monday &ndash; Thursday before 10pm',
			'cutoff_hour' => 22,
			'cutoff_minute' => 0,
			'holiday_ranges' => [],
			'active_weekdays' => [1, 2, 3, 4],
			'require_in_stock' => true,
			'border_color' => '#000000',
			'countdown_color' => '#008000',
		];
	}

	private static function enqueueViteAsset(string $entry, string $handle): void
	{
		$asset_uri = Vite::asset($entry);

		if (str_ends_with($entry, '.js')) {
			wp_enqueue_script($handle, $asset_uri, [], null, true);

			return;
		}

		wp_enqueue_style($handle, $asset_uri, [], null);
	}

	private static function getCurrentAdminPageSlug(): string
	{
		return sanitize_key((string) ($_GET['page'] ?? ''));
	}
}
