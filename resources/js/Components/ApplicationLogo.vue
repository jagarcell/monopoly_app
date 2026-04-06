<template>
    <svg viewBox="0 0 200 240" xmlns="http://www.w3.org/2000/svg">
        <!-- Top hat crown -->
        <rect x="62" y="8" width="76" height="62" rx="5" ry="5" />
        <!-- Hat brim -->
        <ellipse cx="100" cy="70" rx="58" ry="12" />
        <!-- Hat band -->
        <rect x="62" y="58" width="76" height="10" opacity="0.65" />

        <!-- Head / face -->
        <ellipse cx="100" cy="108" rx="38" ry="40" />

        <!-- Eyes -->
        <ellipse cx="85" cy="100" rx="5" ry="4" fill="white" />
        <ellipse cx="115" cy="100" rx="5" ry="4" fill="white" />
        <circle cx="86" cy="100" r="2" fill="white" />
        <circle cx="116" cy="100" r="2" fill="white" />

        <!-- Monocle -->
        <circle cx="115" cy="100" r="10" fill="none" stroke="currentColor" stroke-width="2.5" />
        <line x1="125" y1="100" x2="134" y2="100" stroke="currentColor" stroke-width="2" />

        <!-- Nose -->
        <ellipse cx="100" cy="112" rx="4" ry="3" opacity="0.5" />

        <!-- Mustache -->
        <path d="M80 120 Q85 115 92 118 Q96 120 100 118 Q104 120 108 118 Q115 115 120 120 Q115 125 108 122 Q104 120 100 122 Q96 120 92 122 Q85 125 80 120Z" />

        <!-- Smile (below mustache) -->
        <path d="M90 128 Q100 135 110 128" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />

        <!-- Bow tie -->
        <path d="M88 152 L100 160 L112 152 L112 168 L100 160 L88 168Z" />

        <!-- Suit shoulders / jacket -->
        <path d="M62 148 L88 152 L88 168 L60 180 Q45 186 40 200 L40 240 L160 240 L160 200 Q155 186 140 180 L112 168 L112 152 L138 148 Q155 152 165 164 L165 240 L35 240 L35 164 Q45 152 62 148Z" />

        <!-- Lapel lines -->
        <line x1="88" y1="168" x2="75" y2="210" stroke="currentColor" stroke-width="2" opacity="0.3" />
        <line x1="112" y1="168" x2="125" y2="210" stroke="currentColor" stroke-width="2" opacity="0.3" />

        <!-- Shirt front -->
        <rect x="94" y="168" width="12" height="72" fill="white" opacity="0.15" rx="2" />
    </svg>
</template>
