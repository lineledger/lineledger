@php
    $mode = (auth()->user()?->calculator_mode ?? \App\Enums\CalculatorMode::default())->value;

    // Shared key styling. Keys are plain <button>s (not flux:button) for precise
    // grid control and to avoid Blade component-tag compilation quirks.
    $key = 'flex items-center justify-center rounded-lg border border-zinc-200 bg-white py-2.5 text-sm font-medium text-zinc-700 shadow-xs transition hover:bg-zinc-50 active:bg-zinc-100 dark:border-white/10 dark:bg-white/5 dark:text-zinc-200 dark:hover:bg-white/10';
    $opKey = 'flex items-center justify-center rounded-lg border border-zinc-200 bg-zinc-100 py-2.5 text-sm font-semibold text-zinc-700 shadow-xs transition hover:bg-zinc-200 active:bg-zinc-300 dark:border-white/10 dark:bg-white/10 dark:text-zinc-100 dark:hover:bg-white/15';
    $eqKey = 'flex items-center justify-center rounded-lg bg-blue-600 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-blue-500 active:bg-blue-700';
@endphp

<flux:modal.trigger name="calculator">
    <button
        type="button"
        class="flex size-[34px] shrink-0 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-500 shadow-xs transition hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:text-zinc-400 dark:hover:bg-white/10"
        :aria-label="'{{ __('Calculator') }}'"
        title="{{ __('Calculator') }}"
        data-test="calculator-trigger"
    >
        <flux:icon name="calculator" class="size-4" />
    </button>
</flux:modal.trigger>

<flux:modal name="calculator" class="max-w-xs!" focusable>
    <div
        x-data="tapeCalculator({ mode: '{{ $mode }}' })"
        x-on:keydown="onKey($event)"
        x-on:paste="onPaste($event)"
        tabindex="0"
        autofocus
        class="space-y-3 outline-none"
        data-test="calculator-body"
    >
        <flux:heading size="lg">{{ __('Calculator') }}</flux:heading>

        {{-- Tape --}}
        <div
            x-ref="tape"
            class="h-28 overflow-y-auto rounded-lg border border-zinc-200 bg-zinc-50 p-2 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-900"
            data-test="calculator-tape"
        >
            <template x-if="tape.length === 0">
                <div class="flex h-full items-center justify-center text-zinc-400">{{ __('Tape is empty') }}</div>
            </template>
            <template x-for="(row, idx) in tape" :key="idx">
                <div
                    class="flex justify-between gap-6 py-0.5"
                    :class="row.total ? 'mt-0.5 border-t border-zinc-300 pt-1 font-semibold dark:border-zinc-600' : ''"
                >
                    <span class="w-4 text-zinc-400" x-text="row.sign"></span>
                    <span class="tabular-nums text-zinc-700 dark:text-zinc-200" x-text="row.value"></span>
                </div>
            </template>
        </div>

        {{-- Display --}}
        <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-white/5">
            <span
                class="min-w-0 flex-1 select-text truncate text-right font-mono text-2xl tabular-nums text-zinc-900 dark:text-zinc-100"
                x-text="display"
                data-test="calc-display"
            ></span>
            <flux:button size="xs" variant="subtle" icon="clipboard-document" x-on:click="copy()" :tooltip="__('Copy (⌘C)')" />
            <flux:button
                size="xs"
                variant="subtle"
                icon="arrow-down-on-square"
                x-on:click="place()"
                x-show="hasTarget"
                :tooltip="__('Place in field')"
            />
        </div>

        {{-- Keypad --}}
        <div class="grid grid-cols-4 gap-1.5">
            <button type="button" class="col-span-2 {{ $key }}" x-on:click="clear()">C</button>
            <button type="button" class="{{ $key }}" x-on:click="backspace()">⌫</button>
            <button type="button" class="{{ $opKey }}" :class="(pendingOp === '÷' || mulPending === '÷') ? 'ring-2 ring-blue-500' : ''" x-on:click="op('÷')">÷</button>

            <button type="button" class="{{ $key }}" x-on:click="digit('7')">7</button>
            <button type="button" class="{{ $key }}" x-on:click="digit('8')">8</button>
            <button type="button" class="{{ $key }}" x-on:click="digit('9')">9</button>
            <button type="button" class="{{ $opKey }}" :class="(pendingOp === '×' || mulPending === '×') ? 'ring-2 ring-blue-500' : ''" x-on:click="op('×')">×</button>

            <button type="button" class="{{ $key }}" x-on:click="digit('4')">4</button>
            <button type="button" class="{{ $key }}" x-on:click="digit('5')">5</button>
            <button type="button" class="{{ $key }}" x-on:click="digit('6')">6</button>
            <button type="button" class="{{ $opKey }}" :class="pendingOp === '−' ? 'ring-2 ring-blue-500' : ''" x-on:click="op('−')">−</button>

            <button type="button" class="{{ $key }}" x-on:click="digit('1')">1</button>
            <button type="button" class="{{ $key }}" x-on:click="digit('2')">2</button>
            <button type="button" class="{{ $key }}" x-on:click="digit('3')">3</button>
            <button type="button" class="{{ $opKey }}" :class="pendingOp === '+' ? 'ring-2 ring-blue-500' : ''" x-on:click="op('+')">+</button>

            <button type="button" class="col-span-2 {{ $key }}" x-on:click="digit('0')">0</button>
            <button type="button" class="{{ $key }}" x-on:click="dot()">.</button>
            <button type="button" class="{{ $eqKey }}" x-on:click="equals()">=</button>

            @if ($mode === 'adding_machine')
                <button type="button" class="col-span-4 {{ $eqKey }}" x-on:click="total_()">{{ __('Total') }}</button>
            @endif
        </div>

        <p class="text-center text-[10px] text-zinc-400">
            @if ($mode === 'adding_machine')
                {{ __('+ and − add to the running total · Total prints the grand total') }}
            @else
                {{ __('Type or click · Enter = equals · Esc closes') }}
            @endif
        </p>
    </div>
</flux:modal>
