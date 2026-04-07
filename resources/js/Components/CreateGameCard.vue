<template>
    <div class="relative overflow-hidden rounded-2xl shadow-xl cursor-pointer group" @click="$emit('create')">
        <!-- Monopoly board background -->
        <div class="monopoly-board w-full aspect-square sm:aspect-video" aria-hidden="true">
            <!-- Board border -->
            <div class="absolute inset-0 bg-[#c8e6c9] rounded-2xl" />

            <!-- Corner squares -->
            <!-- GO (bottom-right) -->
            <div class="absolute bottom-0 right-0 w-[18%] h-[18%] bg-white border-2 border-gray-800 flex items-center justify-center">
                <span class="text-[0.45rem] sm:text-xs font-black text-red-600 leading-tight text-center">GO</span>
            </div>
            <!-- Jail (bottom-left) -->
            <div class="absolute bottom-0 left-0 w-[18%] h-[18%] bg-white border-2 border-gray-800 flex items-center justify-center">
                <span class="text-[0.45rem] sm:text-xs font-black text-gray-700 leading-tight text-center">JAIL</span>
            </div>
            <!-- Free Parking (top-left) -->
            <div class="absolute top-0 left-0 w-[18%] h-[18%] bg-white border-2 border-gray-800 flex items-center justify-center">
                <span class="text-[0.4rem] sm:text-[0.6rem] font-black text-teal-700 leading-tight text-center">FREE<br>PARK</span>
            </div>
            <!-- Go To Jail (top-right) -->
            <div class="absolute top-0 right-0 w-[18%] h-[18%] bg-white border-2 border-gray-800 flex items-center justify-center">
                <span class="text-[0.4rem] sm:text-[0.6rem] font-black text-gray-700 leading-tight text-center">GO TO<br>JAIL</span>
            </div>

            <!-- Bottom edge — properties (brown / light blue) -->
            <div class="absolute bottom-0 left-[18%] right-[18%] h-[18%] flex flex-row border-t-2 border-gray-800">
                <div class="flex-1 bg-amber-800 border-r border-gray-700" />
                <div class="flex-1 bg-sky-300 border-r border-gray-700" />
                <div class="flex-1 bg-sky-300 border-r border-gray-700" />
                <div class="flex-1 bg-sky-300 border-r border-gray-700" />
                <div class="flex-1 bg-amber-800 border-r border-gray-700" />
                <div class="flex-1 bg-sky-300 border-r border-gray-700" />
                <div class="flex-1 bg-sky-300 border-r border-gray-700" />
                <div class="flex-1 bg-sky-300" />
            </div>

            <!-- Top edge — properties (yellow / green) -->
            <div class="absolute top-0 left-[18%] right-[18%] h-[18%] flex flex-row border-b-2 border-gray-800">
                <div class="flex-1 bg-red-400 border-r border-gray-700" />
                <div class="flex-1 bg-yellow-300 border-r border-gray-700" />
                <div class="flex-1 bg-yellow-300 border-r border-gray-700" />
                <div class="flex-1 bg-yellow-300 border-r border-gray-700" />
                <div class="flex-1 bg-green-500 border-r border-gray-700" />
                <div class="flex-1 bg-green-500 border-r border-gray-700" />
                <div class="flex-1 bg-green-500 border-r border-gray-700" />
                <div class="flex-1 bg-red-400" />
            </div>

            <!-- Left edge — properties (pink / orange) -->
            <div class="absolute left-0 top-[18%] bottom-[18%] w-[18%] flex flex-col border-r-2 border-gray-800">
                <div class="flex-1 bg-pink-400 border-b border-gray-700" />
                <div class="flex-1 bg-pink-400 border-b border-gray-700" />
                <div class="flex-1 bg-pink-400 border-b border-gray-700" />
                <div class="flex-1 bg-orange-400 border-b border-gray-700" />
                <div class="flex-1 bg-orange-400 border-b border-gray-700" />
                <div class="flex-1 bg-orange-400 border-b border-gray-700" />
                <div class="flex-1 bg-pink-400 border-b border-gray-700" />
                <div class="flex-1 bg-pink-400" />
            </div>

            <!-- Right edge — properties (red / dark blue) -->
            <div class="absolute right-0 top-[18%] bottom-[18%] w-[18%] flex flex-col border-l-2 border-gray-800">
                <div class="flex-1 bg-red-500 border-b border-gray-700" />
                <div class="flex-1 bg-red-500 border-b border-gray-700" />
                <div class="flex-1 bg-red-500 border-b border-gray-700" />
                <div class="flex-1 bg-blue-800 border-b border-gray-700" />
                <div class="flex-1 bg-blue-800 border-b border-gray-700" />
                <div class="flex-1 bg-red-500 border-b border-gray-700" />
                <div class="flex-1 bg-red-500 border-b border-gray-700" />
                <div class="flex-1 bg-red-500" />
            </div>

            <!-- Centre area -->
            <div class="absolute inset-[18%] bg-[#c8e6c9] flex items-center justify-center">
                <span class="text-[0.55rem] sm:text-sm font-black tracking-widest text-green-900 rotate-0 select-none uppercase">
                    Monopoly
                </span>
            </div>
        </div>

        <!-- Dark overlay with CTA -->
        <div
            class="absolute inset-0 bg-black/50 flex flex-col items-center justify-center gap-3 sm:gap-4 transition-colors duration-200 group-hover:bg-black/60 rounded-2xl"
        >
            <div class="text-white text-center px-4">
                <p class="text-lg sm:text-2xl font-bold drop-shadow">Start a New Game</p>
                <p class="text-xs sm:text-sm text-gray-300 mt-1">Roll the dice and build your empire</p>
            </div>
            <button
                type="button"
                class="mt-1 px-5 py-2 sm:px-7 sm:py-3 bg-green-500 hover:bg-green-400 active:bg-green-600 text-white font-bold rounded-full shadow-lg text-sm sm:text-base transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-offset-2"
                @click.stop="$emit('create')"
            >
                Create Game
            </button>
        </div>
    </div>
</template>

<script setup>
defineEmits(['create']);
</script>
