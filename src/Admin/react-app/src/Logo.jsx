/**
 * SlashBooking inline SVG logo.
 *
 * Design: rounded-square plaque with the brand's blue→emerald gradient,
 * a bold white forward-slash (the "/" in SlashBooking), and an amber
 * dot at the top-right tip suggesting a confirmed appointment.
 *
 * Scales perfectly. The PNG export at assets/logo/slashbooking-{size}.png
 * is generated from this same geometry — keep them in sync if you change it.
 */
export default function Logo( { size = 40, gradientId = 'sb-logo-bg' } ) {
	return (
		<svg
			viewBox="0 0 64 64"
			width={ size }
			height={ size }
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
			role="img"
		>
			<defs>
				<linearGradient id={ gradientId } x1="0" y1="0" x2="1" y2="1">
					<stop offset="0%" stopColor="#2563eb" />
					<stop offset="100%" stopColor="#10b981" />
				</linearGradient>
			</defs>
			<rect width="64" height="64" rx="14" fill={ `url(#${ gradientId })` } />
			<path
				d="M 20 50 L 44 14"
				stroke="#ffffff"
				strokeWidth="7"
				strokeLinecap="round"
			/>
			<circle
				cx="44"
				cy="14"
				r="6"
				fill="#fcd34d"
				stroke="#ffffff"
				strokeWidth="2"
			/>
		</svg>
	);
}
