<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Payroll')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Payroll')"
        :subheading="__('Pay Canadian employees, track time and time off, calculate CPP/EI and income tax, write cheques, and prepare your remittances and year-end slips.')"
    >
        <flux:text>
            {{ __('Payroll runs Canadian payroll end to end: you set up each employee once, run pay on a schedule, and the app calculates every statutory deduction, posts the wages to your books, and writes the cheques. Around the pay run it also tracks hours, vacation, sick and other time off, and lets staff request leave and log time from the employee portal. At month end it prepares your CRA remittance; at year end it produces T4s and the rest. Payroll is a Canada-only feature and is turned off until you enable it. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        {{-- ───────────────────────── Turning payroll on ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Turning payroll on') }}</flux:heading>
        <flux:text>
            {{ __('Payroll is off by default. Switch it on for a Canadian company and a Payroll group appears in the sidebar with Overview, Employee setup, Staff calendar, Pay runs, and a Reports section underneath (Remittance history, PD7A, Workers’ comp, T4 slips, Record of Employment, and Calculation check). Two more setup screens — Pay schedules and Time-off policies — live under Settings → Payroll.') }}
        </flux:text>

        <p><strong>{{ __('To enable payroll:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Organizations and select your company to open its edit page.') }}</li>
            <li>{{ __('Scroll to the Features section and turn on Payroll.') }}</li>
            <li>{{ __('Select Save. The Payroll section now shows in the sidebar, and the system payroll accounts (wages, CPP/EI payable, tax payable) are created for you.') }}</li>
        </ol>

        <x-docs.callout type="note">
            {{ __('The Payroll toggle only appears on Canadian companies — the calculations, remittances, and slips are built around the CRA’s rules. United States payroll is not part of the app today.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/payroll/overview.png') }}"
            alt="{{ __('The Payroll overview page with Employee setup, Pay schedules, Pay runs, Staff calendar, and Reports cards and a getting-started checklist') }}"
            caption="{{ __('The Payroll overview. The cards jump to employee setup, pay schedules, pay runs, the staff calendar, and the reports; a yellow banner flags banked overtime past its deadline, and the checklist walks you through first-time setup.') }}"
        />

        <flux:text>
            {{ __('The Overview page (Payroll → Overview) is the hub. It tracks how many of your employees are enrolled in payroll and how many pay schedules are active, and lays out the order to set things up: create a schedule, set up each employee, run payroll, then remit. Work through it in that order the first time.') }}
        </flux:text>

        {{-- ───────────────────────── Pay schedules ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create a pay schedule') }}</flux:heading>
        <flux:text>
            {{ __('A pay schedule says how often you pay — weekly, bi-weekly, semi-monthly, or monthly. It does more than set a calendar: the frequency tells the app how to annualize each pay cheque so CPP, EI, and income tax come out right. Set up one schedule for each pay cadence you run.') }}
        </flux:text>

        <p><strong>{{ __('To create a pay schedule:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Payroll → Pay schedules — or use the Pay schedules card on the Payroll overview — and select New schedule.') }}</li>
            <li>{{ __('Give it a Name (for example “Bi-weekly”) and choose the Frequency.') }}</li>
            <li>{{ __('Set the Anchor period end date — the end of any one reference pay period; the app projects future periods from it.') }}</li>
            <li>{{ __('Optionally set Pay date offset (days after period end) — how many days after a period closes that payday falls.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        {{-- ───────────────────────── Employee setup ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Set up an employee for payroll') }}</flux:heading>
        <flux:text>
            {{ __('Every employee you pay needs a payroll profile — the province they work in, their pay rate, the claim amounts from their TD1 forms, a vacation policy, and any time-off types they earn. The app reads all of it each pay run, so you only enter it once. Employees come from the Employees area; here you enrol them in payroll.') }}
        </flux:text>

        <p><strong>{{ __('To set up an employee:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Payroll → Employee setup and select Set up next to the employee.') }}</li>
            <li>{{ __('Under Identity, enter the Social Insurance Number, date of birth, and hire date. Set a Termination date only when they leave — it is what drives the Record of Employment.') }}</li>
            <li>{{ __('Under Pay, choose the Province of employment, a Pay schedule, and the Pay basis — Salary, Hourly, or Commission — then the annual salary, or the hourly rate and default hours per period (commission-only staff are paid from commission earnings you add on the run).') }}</li>
            <li>{{ __('Under Tax credits (TD1), enter the Federal claim amount and the Provincial claim amount from the employee’s TD1 forms. Add an Additional tax per pay if they have asked for extra withheld.') }}</li>
            <li>{{ __('Under Vacation & posting, pick a Vacation policy — Accrue to liability or Pay on every cheque — a vacation rate (4% by default), and a Time-off approver (who reviews this person’s leave requests first; blank means any payroll user can).') }}</li>
            <li>{{ __('Assign any Time-off policies the employee earns and give each an opening balance, so their vacation, sick, or personal time starts from the right number.') }}</li>
            <li>{{ __('Optionally turn on Banked overtime, where the province allows it, so the employee can bank overtime as paid time off instead of being paid it out.') }}</li>
            <li>{{ __('Select Save payroll setup.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/payroll/employee-setup.png') }}"
            alt="{{ __('The employee payroll setup form with Identity, Pay, Tax credits (TD1), Vacation & posting, time-off policy, and banked overtime sections') }}"
            caption="{{ __('The employee payroll setup form. The province of employment decides which deductions apply; the TD1 claim amounts set how much tax is withheld; the time-off and banked-overtime sections set what leave they earn.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('CPP, EI, and exemptions') }}">
            {{ __('Most employees need no exemptions. If one is genuinely exempt — a sole owner past the CPP age limit, say — turn on CPP exempt or EI exempt on their profile and the app stops deducting it. By default it deducts both, capped automatically once the employee hits the annual CPP and EI maximums.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Time-off policies ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Time-off policies') }}</flux:heading>
        <flux:text>
            {{ __('A time-off policy is a company-wide preset for one kind of leave — how it accrues, its annual cap, how much carries over, and whether it is paid. You build the policies once, then assign them to employees on the setup page. Balances roll forward across pay runs, so the app always knows how much vacation or sick time someone has banked.') }}
        </flux:text>

        <p><strong>{{ __('To create a time-off policy:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Payroll → Time-off policies and select New policy.') }}</li>
            <li>{{ __('Give it a Name and pick a Category — Vacation, Sick, Personal, Bereavement, Banked time, Other, or Unpaid. The category sets the colour the leave shows in on the calendars.') }}</li>
            <li>{{ __('Choose a Unit: Hours for most leave, or Dollars for a percent-of-earnings policy like vacation pay.') }}</li>
            <li>{{ __('Choose an Accrual method and the rate beside it (the rate label changes to match): Per pay period or Per hour worked accrue automatically on each pay run; Beginning of year and On work anniversary grant an annual lump; Manual only never auto-accrues.') }}</li>
            <li>{{ __('Optionally set an Annual cap (the most that accrues in a year) and a Carryover max (the most that carries into the next year). Leave them blank for no limit.') }}</li>
            <li>{{ __('Turn on Paid time off if the leave is paid, Use for new employees to assign it automatically, and Active to keep it selectable. Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/payroll/time-off-policy.png') }}"
            alt="{{ __('The New time-off policy form with category, unit, accrual method, rate, annual cap, carryover, and paid toggles') }}"
            caption="{{ __('A time-off policy. The accrual method decides how the balance grows; the cap and carryover keep it in bounds; “Use for new employees” hands it to everyone automatically.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Per-pay-period and per-hour-worked policies top up the balance every time you post a pay run. Beginning-of-year and anniversary policies are granted once a year by a nightly task (payroll:accrue-time-off), which also rolls last year’s balance over within the carryover limit — so you do not have to grant or reset anything by hand.') }}
        </x-docs.callout>

        {{-- ───────────────── Time-off requests and approvals ───────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Time-off requests and approvals') }}</flux:heading>
        <flux:text>
            {{ __('When someone wants vacation or a sick day, it flows through a two-step approval. An employee submits a request from the portal (or you record one on their behalf); a manager accepts the absence; then payroll confirms the pay treatment, which schedules the days so the pay run picks them up. Each step keeps everyone — and the books — in sync.') }}
        </flux:text>

        <p><strong>{{ __('To review a request:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Time-off requests (from the Staff calendar’s Time-off requests button, or the link in the approval email). Open requests — pending, manager-approved, and approved — show first.') }}</li>
            <li>{{ __('Select Review on a request to see the dates, hours, the approver, and a Balance check that shows how much the employee has, how much is already in flight, and what would be left.') }}</li>
            <li>{{ __('As a manager, select Approve absence to accept it (payroll confirms pay next), or Deny.') }}</li>
            <li>{{ __('To finish it in one step, select Approve + confirm pay; payroll users can also Confirm pay treatment on an already-accepted request.') }}</li>
            <li>{{ __('Leave the “Schedule the days as approved time entries for payroll” switch on so the leave is paid automatically on the next run.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/payroll/time-off-request.png') }}"
            alt="{{ __('The time-off request review panel showing dates, hours, approver, a balance check, and the approve and deny buttons') }}"
            caption="{{ __('Reviewing a time-off request. The balance check warns when approving would take the balance negative — it still lets you approve, but flags it.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('How leave becomes paid time') }}">
            {{ __('Confirming the pay treatment creates an approved time entry for each working day of the request, tagged with the policy’s pay code. The pay run reads those entries, pays the time according to the policy (paid or unpaid), and draws the matching balance down — so a five-day vacation lowers the employee’s vacation balance by five days’ worth of hours.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Need to enter leave for someone yourself? Select Record a request on the Time-off requests page, pick the employee, the time-off type, the dates, and the hours per day, and it enters the same approval pipeline.') }}
        </flux:text>

        {{-- ───────────────────────── Staff calendar ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The staff calendar') }}</flux:heading>
        <flux:text>
            {{ __('The staff calendar (Payroll → Staff calendar) is a month grid of who is away when. Approved time off shows as a solid chip in the leave type’s colour; requests still in approval show as a dashed amber chip, so you can spot clashes before you say yes.') }}
        </flux:text>

        <p><strong>{{ __('To use the staff calendar:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Payroll → Staff calendar. Use the arrows or Today to move between months.') }}</li>
            <li>{{ __('Filter by a single employee or a single leave type, or hide in-flight requests with the Show pending toggle.') }}</li>
            <li>{{ __('Select a day to open its panel, then approve or deny the requests on that day right from the calendar.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/payroll/staff-calendar.png') }}"
            alt="{{ __('The staff calendar month grid with employee absence chips, colour-coded by leave type, and employee and type filters') }}"
            caption="{{ __('The staff calendar. Solid chips are approved absences; dashed amber chips are requests still waiting on a decision.') }}"
        />

        {{-- ───────────────────────── Time tracking and pay codes ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Time tracking and pay codes') }}</flux:heading>
        <flux:text>
            {{ __('Hourly staff (and anyone with overtime or time off to record) can log their hours, and you bring those hours into the pay run instead of typing them in. Every entry carries a pay code that tells payroll how to treat it.') }}
        </flux:text>

        <flux:text>
            {{ __('The pay codes are the wage codes every company has — Regular, Overtime, Double overtime, and Statutory holiday — plus Banked overtime for anyone set up to bank it, and one code for each active time-off policy (Sick, Vacation, and so on). A wage code prices the hours by its multiplier; a time-off code pays according to the policy and draws that balance down.') }}
        </flux:text>

        <p><strong>{{ __('How time flows into a pay run:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('An employee logs hours in the employee portal under My time — picking a date, the hours, and a pay code — or you record them for staff and approve them.') }}</li>
            <li>{{ __('Approved, unpaid entries wait until the next pay run for that period.') }}</li>
            <li>{{ __('On the pay run, select Pull hours from time entries to bring each hourly employee’s approved hours (and any overtime) into the run.') }}</li>
            <li>{{ __('Billable time can instead be turned into a customer invoice — it never gets double-counted, because each entry is marked once it is paid or billed.') }}</li>
        </ol>

        <x-docs.callout type="tip" heading="{{ __('The employee portal') }}">
            {{ __('Employees with a portal login get a My time calendar to log hours and a Time off page that shows their balances, lets them request leave (with a live “what would be left” projection), and — if you turn it on under Settings → Payroll — a team calendar of who else is away. They only ever see the pay codes and time-off types that apply to them.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Run a pay run ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Run payroll') }}</flux:heading>
        <flux:text>
            {{ __('A pay run pays a group of employees for one pay period. You pick the period and who is in it, the app calculates everyone’s deductions, you review the numbers, and then you post the run to your books and write the cheques.') }}
        </flux:text>

        <p><strong>{{ __('To run payroll:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Payroll → Pay runs and select New pay run.') }}</li>
            <li>{{ __('Choose the Pay schedule and the Pay from bank account, then set the Period start, Period end, and Pay date.') }}</li>
            <li>{{ __('Tick the employees to include, and enter hours for anyone paid hourly — or select Pull hours from time entries to bring in their approved, unpaid hours and overtime for the period.') }}</li>
            <li>{{ __('Select Calculate. The app computes gross pay and every deduction and opens the pay run for review. (Save draft keeps it without calculating.)') }}</li>
            <li>{{ __('Check each line — gross, CPP, EI, federal and provincial tax, and net. Use Adjust on a line to override any single deduction, or Recalculate after a change.') }}</li>
            <li>{{ __('Select Post pay run to record the wages, deductions, and employer cost in the general ledger.') }}</li>
            <li>{{ __('Select Write cheques, confirm the bank account, and enter the starting cheque number; the app writes one numbered cheque per employee paid. Select Print beside any cheque for pre-printed cheque stock, or Void a single cheque to reverse just its bank entry if you misprint one.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/payroll/pay-run.png') }}"
            alt="{{ __('A calculated pay run showing per-employee gross, CPP, EI, federal and provincial tax, and net pay with totals across the top') }}"
            caption="{{ __('A calculated pay run. The tiles total gross, deductions, net pay, and employer cost; each row breaks down one employee’s cheque.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What posting a pay run does to your books') }}">
            {{ __('Posting debits your wage-expense account for the gross pay and the employer’s share of CPP and EI, and credits the bank for the net pay with each statutory deduction sitting in its own payable account until you remit it. A pay run moves through Draft → Calculated → Posted → Paid; you can Void a posted run to reverse the whole journal entry if you catch a mistake.') }}
        </x-docs.callout>

        {{-- ───────────────────────── What gets calculated ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('What the app calculates') }}</flux:heading>
        <flux:text>
            {{ __('For each employee on a pay run, the deduction engine works out:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('CPP — Canada Pension Plan, including the second-tier CPP2 above the higher earnings threshold, capped at the annual maximum.') }}</li>
            <li>{{ __('EI — Employment Insurance, capped at the annual maximum.') }}</li>
            <li>{{ __('Federal and provincial income tax — from the TD1 claim amounts and the pay frequency, with any Additional tax per pay added on top.') }}</li>
            <li>{{ __('Vacation and other time off — accrued to a liability account or paid out on the cheque, at the rate on each policy and the employee’s profile.') }}</li>
            <li>{{ __('Employer cost — the employer’s matching CPP and EI, so you can see the true cost of the run, not just the net pay.') }}</li>
        </ul>
        <flux:text>
            {{ __('Year-to-date totals carry across pay runs, so the annual CPP and EI maximums are respected automatically — an employee who has already hit the ceiling stops having it deducted.') }}
        </flux:text>

        <x-docs.callout type="tip" heading="{{ __('Check the math against the CRA') }}">
            {{ __('The Calculation check report (Payroll → Calculation check) runs the deduction engine against the CRA’s published reference figures and shows where each one lands. It is there to give you — and your accountant — confidence that the CPP, EI, and tax numbers match the official tables before you rely on them.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Tax rates & effective dates ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Tax rates and effective dates') }}</flux:heading>
        <flux:text>
            {{ __('The app carries the CRA Payroll Deductions Formulas (T4127) tables for CPP, EI, and federal and provincial income tax, plus Quebec’s QPP, QPIP, and Quebec-EI figures. The CRA revises these every January 1, and again on July 1 when a budget changes the rules mid-year. Each pay run automatically uses the table in effect on its pay date, so a June and a July cheque in the same year can be calculated on different rates without you doing anything.') }}
        </flux:text>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Effective') }}</flux:table.column>
                <flux:table.column>{{ __('What changed') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('January 1, 2025') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Baseline 2025 tables — CPP (YMPE $71,300) and EI ($65,700), and the 2025 federal and provincial income-tax brackets (T4127 120th edition).') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('July 1, 2025') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Federal lowest tax rate cut from 15% to 14%; Alberta’s new 8% bracket on the first $60,000 (prorated); Manitoba’s basic personal amount frozen; Nova Scotia, PEI, and Saskatchewan basic-amount changes (121st edition).') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('January 1, 2026') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Indexed brackets and amounts; CPP YMPE $74,600 and EI maximum $68,900; Quebec’s QPP rate cut to 6.30% and QPIP rate cut with a higher $103,000 ceiling (122nd edition).') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('July 1, 2026') }}</flux:table.cell>
                    <flux:table.cell>{{ __('British Columbia’s lowest rate raised (prorated); Newfoundland and Labrador’s basic personal amount increased; PEI’s new bracket on income over $200,000 (123rd edition).') }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>

        <x-docs.callout type="note">
            {{ __('Quebec’s provincial income-tax brackets come from Revenu Québec (TP-1015) rather than the CRA. A few minor low-income credits — the provincial tax-reduction credit and Alberta’s supplemental credit — are intentionally left out; their omission withholds a little extra, which the employee recovers when they file, never the other way around.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Quebec ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Quebec payroll') }}</flux:heading>
        <flux:text>
            {{ __('Quebec runs its own parallel system, and the app handles it automatically. Set an employee’s Province of employment to Quebec and their deductions switch from the federal set to the Quebec one — there is nothing extra to configure on the pay run itself.') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('QPP replaces CPP — the Quebec Pension Plan, with its own QPP2 second tier.') }}</li>
            <li>{{ __('QPIP — the Quebec Parental Insurance Plan, deducted alongside EI. You can mark a specific employee QPIP exempt on their profile.') }}</li>
            <li>{{ __('Quebec provincial income tax — withheld to Revenu Québec using the employee’s TP-1015.3 source-deductions claim instead of a regular provincial claim.') }}</li>
        </ul>

        <flux:text>
            {{ __('Two of the Quebec amounts are employer levies you set once on the company, because they apply to the whole Quebec payroll rather than to one person. Open Settings → Organizations → your company, scroll to Quebec payroll, and enter:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('QHSF rate (%) — the Health Services Fund, charged on Quebec gross pay.') }}</li>
            <li>{{ __('CNESST rate (%) — the occupational health-and-safety levy, charged on Quebec insurable earnings.') }}</li>
            <li>{{ __('Subject to the 1% workforce skills development levy (WSDRF) — tick it if it applies; it is reconciled on the RL-1 Summary.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/payroll/revenu-quebec.png') }}"
            alt="{{ __('The Revenu Québec remittance report showing Quebec tax, QPP, QPIP, QHSF, and CNESST for a period') }}"
            caption="{{ __('The Revenu Québec remittance brings together Quebec tax, QPP, QPIP, and the QHSF and CNESST employer levies for the period.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Leave the QHSF and CNESST rates at 0 if you have no Quebec employees or no levy to pay — nothing Quebec-specific is calculated until an employee’s province of employment is set to Quebec.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Reports ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Remittances and year-end slips') }}</flux:heading>
        <flux:text>
            {{ __('Each payroll report reads straight from your posted pay runs, so they are always in step with what you actually paid. Pick the year (and month, for remittances) and the figures fill in.') }}
        </flux:text>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Report') }}</flux:table.column>
                <flux:table.column>{{ __('What it is for') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('PD7A remittance') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The federal tax, CPP, and EI you owe the CRA for a remitting period.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('Revenu Québec remittance') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The Quebec tax, QPP, QPIP, QHSF, and CNESST you owe Revenu Québec (Quebec employees).') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('Workers’ comp') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The WSIB/WCB employer assessment to remit for a period, at each province’s rate (Quebec is covered by CNESST instead).') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('Remittance history') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Every remittance you have recorded, so you can see what is paid and what is still due.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('Payroll register') }}</flux:table.cell>
                    <flux:table.cell>{{ __('A line-by-line record of earnings and deductions across your pay runs for a period.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('T4 slips') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Year-end employment-income slips for each employee, with a CRA-ready XML export.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('T4A slips') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Year-end slips for other amounts paid, filtered by the reporting threshold.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('RL-1 slips') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Quebec year-end employment-income slips, with a Revenu Québec XML export.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('Record of Employment') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The ROE for an employee who has left — pick them, their last day, and the reason.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('Calculation check') }}</flux:table.cell>
                    <flux:table.cell>{{ __('A live check that the CPP/EI/tax engine matches the CRA’s reference figures.') }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>

        <x-docs.figure
            src="{{ asset('docs/screenshots/payroll/pd7a.png') }}"
            alt="{{ __('The PD7A remittance report showing federal tax, CPP, and EI owed to the CRA for a period') }}"
            caption="{{ __('The PD7A remittance. Run it each remitting period to see exactly what to send the CRA.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('File-ready exports') }}">
            {{ __('The T4 and RL-1 reports produce both printable slips and the XML file the CRA and Revenu Québec accept for electronic filing, so a small employer can prepare and file year-end slips without separate software.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Related reports ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('PD7A remittance — federal tax, CPP, and EI owed to the CRA per period.') }}</li>
            <li>{{ __('Revenu Québec remittance — Quebec tax, QPP, QPIP, QHSF, and CNESST per period.') }}</li>
            <li>{{ __('Workers’ comp — the WSIB/WCB employer assessment to remit per period.') }}</li>
            <li>{{ __('Payroll register — earnings and deductions line by line across your pay runs.') }}</li>
            <li>{{ __('T4 / T4A / RL-1 — year-end slips with file-ready XML exports.') }}</li>
            <li>{{ __('Record of Employment — the ROE for a departing employee.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
