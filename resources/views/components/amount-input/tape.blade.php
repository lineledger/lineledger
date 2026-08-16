{{--
    Calculator "tape" for <x-amount-input>. Rendered inline inside the parent's
    `amountCalculator` Alpine scope (showTape / steps / result). Kept in its own
    file so the parent's <flux:input> is followed by a single short component
    tag — a tall pile of markup directly after a Flux tag trips catastrophic
    backtracking in Blade's component-tag compiler and leaves the tag uncompiled.
--}}
<div
    x-ref="tape"
    x-show="showTape"
    x-cloak
    x-transition.opacity
    class="fixed z-50 min-w-40 rounded-lg border border-border bg-card p-2 font-mono text-xs shadow-lg"
    data-test="calc-tape"
>
    <template x-for="(step, idx) in steps" :key="idx">
        <div class="flex justify-between gap-6 py-0.5">
            <span class="w-3 text-muted-foreground" x-text="step.op"></span>
            <span class="tabular-nums text-foreground" x-text="step.value"></span>
        </div>
    </template>
    <div class="mt-1 flex justify-between gap-6 border-t border-border pt-1 font-semibold">
        <span class="w-3 text-muted-foreground">=</span>
        <span class="tabular-nums" data-test="calc-result" x-text="result"></span>
    </div>
    <div class="mt-1 font-sans text-[10px] text-muted-foreground">{{ __('Press Enter') }}</div>
</div>
