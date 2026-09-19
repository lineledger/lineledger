<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Employee portal')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Employee portal')"
        :subheading="__('A self-serve site where your employees see their own pay statements and tax slips, log hours, request time off, and keep their address and TD1 amounts current.')"
    >
        <flux:text>
            {{ __('The employee portal is a separate, employee-facing site for your organization. It lives at a /my-pay address, needs no staff account, and shows each employee only their own records — never your books, never anyone else’s pay. An employee signs in to download pay statements and year-end slips, check vacation and sick balances, log time, request leave, and update the details you would otherwise have to type in for them. It is part of Payroll, so it exists only for a Canadian organization with Payroll turned on. The examples below use our sample business, Demo Company Inc., and its employee Jordan Avery.') }}
        </flux:text>

        <flux:text>
            {{ __('Like the customer portal, the page is deliberately plain: your logo (or your organization’s name if you have not uploaded one), the signed-in employee’s name, a Sign out button, and nothing else. Customers have a portal of their own at a separate /pay address — see the') }}
            <a class="underline" href="{{ route('docs.customer-portal') }}" wire:navigate>{{ __('Customer portal') }}</a>{{ __(' page. Everything below is about employees.') }}
        </flux:text>

        {{-- ───────────────────────── Invite an employee ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Invite an employee') }}</flux:heading>
        <flux:text>
            {{ __('Access starts with an invite from you. The portal has no sign-up page: an employee can only get in if their record is active, has an email address, and has an active payroll profile. You send the invite from that profile, and the same button works again any time they need a fresh link.') }}
        </flux:text>

        <p><strong>{{ __('To invite an employee:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Employees from the sidebar and make sure the employee’s record has an Email — it is the address the invite goes to, and the address they will sign in with. See') }} <a class="underline" href="{{ route('docs.employees') }}" wire:navigate>{{ __('Employees') }}</a>{{ __('.') }}</li>
            <li>{{ __('Open Payroll → Employee setup and select Set up (or Edit) on the employee. Complete their payroll profile, leave Active for payroll switched on, and select Save payroll setup. The app confirms “Payroll setup saved.” and returns you to the Employee payroll setup list. Setting up a profile is covered on the') }} <a class="underline" href="{{ route('docs.payroll') }}" wire:navigate>{{ __('Payroll') }}</a>{{ __(' page.') }}</li>
            <li>{{ __('Select Edit on the employee to reopen the saved profile, then select Send portal invite in the header — the button only appears once the profile exists. The app confirms with “Portal invite sent to jordan@demo.example.”') }}</li>
            <li>{{ __('The employee receives an email headed “Sign in to your pay portal” with a View my pay button. Opening it signs them straight in.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/employee-portal/invite.png') }}"
            alt="{{ __('The header of Jordan Avery’s payroll profile under Payroll → Employee setup, with the Send portal invite and Terminate & issue ROE buttons beside the employee’s name') }}"
            caption="{{ __('Send portal invite sits in the header of a saved payroll profile. Select it again whenever an employee needs a new sign-in link.') }}"
        />

        <flux:text>
            {{ __('If the employee has no email, or their record or payroll profile is inactive, nothing is sent and the app shows a warning: “Add an email address and save the profile before inviting this employee.” The warning is the same whatever the reason, so if the employee already has an email, check that Active is switched on in their record under Employees and Active for payroll is switched on in their payroll profile. The email itself is branded as your organization — your name (or Brand name) in the header and footer and as the sender name — and comes from a no-reply address, so replies do not reach anyone. The link inside it works once and expires 15 minutes after it was sent; an employee who misses that window simply requests their own link from the portal, as described next.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('One person edits a record at a time') }}">
            {{ __('A payroll profile is edit-locked while someone has it open, and the employee’s own Edit my info page respects the same lock: while you are editing their profile they are asked to try again in a few minutes, and if you save while their page is open they are asked to reload before saving. Owners and Admins can take over a lock a teammate holds. See') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings → Edit locks') }}</a>
            {{ __('for how a lock is held and released.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Signing in ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('How an employee signs in') }}</flux:heading>
        <flux:text>
            {{ __('The portal’s address is your LineLedger address followed by /my-pay/ and your organization’s slug — for Demo Company Inc. that is /my-pay/demo. The sign-in screen is headed “View your pay statements & tax slips” and offers two ways in: an email and password, or a one-time link by email. Nobody needs a password to start; the emailed link is the default, and a password is something the employee chooses to set later so they can skip the email step.') }}
        </flux:text>

        <p><strong>{{ __('To sign in with an emailed link:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('On the sign-in screen, enter your Email address and select Email me a sign-in link.') }}</li>
            <li>{{ __('The screen changes to “Check your email — If an account exists for that address, we just sent a sign-in link. It expires in 15 minutes.” Use a different email takes you back if you mistyped.') }}</li>
            <li>{{ __('Open the “Sign in to your … pay portal” email and select View my pay. You land on your dashboard.') }}</li>
        </ol>

        <p><strong>{{ __('To sign in with a password:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Sign in once with an emailed link, open Edit my info, and set a password under Account & security (see Edit my info below).') }}</li>
            <li>{{ __('From then on, enter your Email address and Password on the sign-in screen and select Sign in. Forgot it? Email me a sign-in link still works and doubles as the reset path — sign in with the link, then set a new password.') }}</li>
        </ol>

        <x-docs.callout type="note" heading="{{ __('What keeps the door secure') }}">
            {{ __('The screen always says a link was sent if an account exists, whether or not the email matched, so the form cannot be used to discover who works for you; a wrong password gets the same generic “These credentials do not match our records.” Each link works once and dies after 15 minutes — a used or expired one bounces back to the sign-in screen with “That sign-in link is invalid or has expired. Please request a new one.” After five attempts for the same address in a short span, the form asks the employee to wait. Employee sessions are entirely separate from your staff logins. The portal never asks an employee for a SIN or banking details, but it does show the SIN on their own documents: a T4 printed on the official CRA form shows the full SIN, while pay statements, RL-1 slips, and a T4 printed as a labelled facsimile show only its last four digits.') }}
        </x-docs.callout>

        <flux:heading size="md" class="mt-6">{{ __('Ending someone’s access') }}</flux:heading>
        <flux:text>
            {{ __('There is no separate “revoke portal access” switch, because access follows the employee’s status. Switch off Active in the employee’s edit dialog under Employees, switch off Active for payroll on their payroll profile, or run Terminate & issue ROE (which deactivates the profile for you), and from that moment no new sign-in link can be requested for them and their password no longer signs them in. A sign-in link already in their inbox is different: only switching off Active under Employees stops it, so if you deactivate just the payroll profile, a link sent in the previous 15 minutes still signs them in. To close every door, switch off Active under Employees as well. None of these ends a session that is already open — it lasts until they select Sign out or it times out on its own.') }}
        </flux:text>

        {{-- ───────────────────────── For employees ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('For employees: your dashboard') }}</flux:heading>
        <flux:text>
            {{ __('After signing in you land on a page headed “Hello,” and your name, with three buttons in the corner — My time, Time off, and Edit my info — and everything about your pay below them. Two cards come first. Year to date shows your Gross, Deductions, and Net pay for the year as of your most recent pay statement (or “No posted pay yet.” before your first). Balances shows your Vacation pay in dollars, if your employer accrues it for you, and the hours left in each time-off type you have been assigned — “No balances to show.” if neither applies yet.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/employee-portal/dashboard.png') }}"
            alt="{{ __('The employee portal dashboard headed “Hello, Jordan Avery” with My time, Time off, and Edit my info buttons, a Year to date card, a Balances card, the Pay statements table with a PDF button on each row, and the Tax slips section') }}"
            caption="{{ __('The dashboard. Year to date and Balances come from your posted pay runs; every row under Pay statements has a PDF button, and tax slips appear once your employer has issued them.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Pay statements') }}</flux:heading>
        <flux:text>
            {{ __('Pay statements lists one row per pay you have received — the Pay date, the Run number, and your Net pay — newest first. Select PDF on a row to open that pay statement in your browser, where you can save or print it. It is the same statement your employer prints: Earnings, Employer-paid benefits & accruals, and Deductions, each with a Current and a YTD column, then Gross, Deductions, and Net pay. Only pay runs your employer has posted appear here; a run still being prepared is not a statement yet, so you never see a preview or a draft.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/employee-portal/pay-stub.png') }}"
            alt="{{ __('Jordan Avery’s pay statement PDF opened from the portal, showing the Earnings, Employer-paid benefits & accruals, and Deductions tables with Current and YTD columns and the Gross, Deductions, and Net pay totals') }}"
            caption="{{ __('A pay statement opened from the PDF button. It is identical to the copy your employer prints from the pay run.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Tax slips') }}</flux:heading>
        <flux:text>
            {{ __('Tax slips shows a button for each year you have a T4 slip, and — if you work in Quebec — each year you have an RL-1 slip. Select a year to download the slip as a PDF. Slips appear only after your employer has finalized that year’s filing, and disappear again if they unlock it to make a correction. Until you have at least one slip, the section reads “Your employer hasn’t issued your tax slips for this year yet.” Once you have a slip from an earlier year, a year that is not finalized yet simply has no button, and no message appears. Employers: Finalize sits on the T4 slips and RL-1 slips pages, and the confirmation spells out that it publishes the slips to the portal — see') }}
            <a class="underline" href="{{ route('docs.payroll') }}" wire:navigate>{{ __('Payroll') }}</a>{{ __('.') }}
        </flux:text>

        {{-- ───────────────────────── My time ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Log your hours (My time)') }}</flux:heading>
        <flux:text>
            {{ __('My time is where you record the hours you have worked, or hours of overtime or time off you have taken, so payroll can pay them. Every entry you log arrives as Pending and is reviewed by your employer before it is paid; the page says so under its heading. It opens on a Calendar of the current month — each day shows its total hours and a coloured dot per entry (amber for pending, green for approved, red for rejected) — with the month’s total in the corner and arrows or Today to move around. List switches to a table of every entry with its Date, Hours, Pay type, Notes, and Status.') }}
        </flux:text>

        <p><strong>{{ __('To log time:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Select Log time, or select an empty day on the calendar (a day that already has entries opens a panel listing them, with its own Log time button).') }}</li>
            <li>{{ __('Confirm the Date and enter the Hours (in quarter-hour steps, up to 24).') }}</li>
            <li>{{ __('Choose the Pay type: Regular pay for ordinary work, an overtime type, or one of your time-off types such as Sick leave. You only see the types that apply to you.') }}</li>
            <li>{{ __('Add Notes, and a Class / project if your employer uses them. If the hours are for a client, turn on Billable to a customer and pick the Customer and the Service — the billing rate is your employer’s decision, so there is nothing to price.') }}</li>
            <li>{{ __('Select Save. The app confirms “Time logged. It will be reviewed before it’s paid.”') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/employee-portal/my-time.png') }}"
            alt="{{ __('The My time page in Calendar view with the month grid, a day showing logged hours and a pending dot, the Calendar and List toggles, the month total, and the Log time button') }}"
            caption="{{ __('My time. Select a day or Log time to add an entry; dots show each entry’s status until your employer has reviewed it.') }}"
        />

        <flux:text>
            {{ __('While an entry is still Pending you can edit or remove it from its row (or from the day panel); once it has been approved, rejected, paid, or billed it is locked. Editing an entry also shows its Edit history — who changed what and when, with your own changes marked “You” — so an approval or a correction by your employer is never a mystery. Employers: pending entries land on the Time entries page for approval, and approved hours are pulled into the next pay run; see Time tracking on the') }}
            <a class="underline" href="{{ route('docs.payroll') }}" wire:navigate>{{ __('Payroll') }}</a>{{ __(' page.') }}
        </flux:text>

        {{-- ───────────────────────── Time off ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Request time off') }}</flux:heading>
        <flux:text>
            {{ __('Time off shows a balance card for each time-off type you have — hours for most, dollars for a vacation-pay type — followed by the Request time off form, the team calendar if your employer has it on, and a table of your requests with their status. If no cards appear, the page explains that no time-off types have been assigned to you yet and that your employer can assign one on your employee setup page.') }}
        </flux:text>

        <p><strong>{{ __('To request time off:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Choose the Type of leave. For an hours-based type the form immediately shows a projection: how many hours you have available, how many are already requested or scheduled, and how many would remain.') }}</li>
            <li>{{ __('Check Hours per day — it is pre-filled from your usual working day — then set the First day and Last day. Weekends are skipped, so a Monday-to-Friday request counts five days.') }}</li>
            <li>{{ __('Add a Note for your approver and select Send request. The app confirms “Request sent — you’ll get an email once it’s decided.”') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/employee-portal/time-off-request.png') }}"
            alt="{{ __('The Time off page showing the Sick leave balance card, the Request time off form with Type, Hours per day, First day, Last day, the available-hours projection and a Note for your approver, and the request table below') }}"
            caption="{{ __('Requesting time off. Pick the type and dates, read the projection of what would remain, and Send request.') }}"
        />

        <flux:text>
            {{ __('Your request starts as Pending. A manager first accepts the absence (Manager approved), then payroll confirms how it will be paid, which moves it to Approved and schedules the days so the next pay run pays them — or it is Denied. You get an email at the decision — “Your time-off request was approved”, for example — with the comment payroll left on the final decision or, if there is none, the comment from the manager who accepted the absence. Only the final decision’s comment shows in your table, as a speech-bubble icon beside the status; a manager’s comment reaches you by email only. You can Withdraw a request while it is Pending or Manager approved; after that, ask your employer to cancel it. The Team time off calendar, when your employer shows it, lists who is away on each day — names and dates of approved absences only, never the reason or anyone’s balance.') }}
        </flux:text>

        <x-docs.callout type="tip" heading="{{ __('Employers: how requests reach you') }}">
            {{ __('A new request emails the employee’s Time-off approver — set on their payroll profile — or, when that is blank, every member with access to Payroll. The email’s Review the request button opens Time-off requests, which is also reachable from the Time-off requests button on Payroll → Staff calendar. Approve absence, Approve + confirm pay, and Deny are described under Time-off requests and approvals on the') }}
            <a class="underline" href="{{ route('docs.payroll') }}" wire:navigate>{{ __('Payroll') }}</a>
            {{ __('page. The team calendar is switched on and off under Settings → Payroll → Show the team time-off calendar in the employee portal.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Edit my info ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Edit my info') }}</flux:heading>
        <flux:text>
            {{ __('Edit my info is the one place an employee can change their own records, and it is deliberately narrow: a mailing address, the TD1 claim amounts, and a portal password. Nothing else on the employee record — pay rate, SIN, exemptions, deductions — can be touched from the portal.') }}
        </flux:text>

        <p><strong>{{ __('To update your address or TD1 amounts:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Under Mailing address, fill in Address line 1, Address line 2, City, Province / region, Postal code, and the two-letter Country code. This is the address that prints on your tax slips.') }}</li>
            <li>{{ __('Under Tax credits (TD1), enter the total claim amounts from your federal and provincial TD1 forms — Federal claim amount and Provincial claim amount — and, if your form gives one, the Federal claim code and Provincial claim code. Changes apply to future pay runs; pay already posted is not recalculated.') }}</li>
            <li>{{ __('Select Save changes. The app confirms “Your info has been updated.” and returns you to the dashboard.') }}</li>
        </ol>

        <p><strong>{{ __('To set or change your password:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Under Account & security, enter a New password and Confirm new password. If you already have one, enter your Current password first.') }}</li>
            <li>{{ __('Select Set password (or Change password). The app confirms “Your password has been saved.”, and from now on Email address plus Password signs you in directly.') }}</li>
        </ol>

        <x-docs.callout type="warning" heading="{{ __('If your employer is editing your record') }}">
            {{ __('Your employer edits the same record from their side. If they have it open when you save, you see “Your employer is updating your details right now. Please try again in a few minutes.” If they saved a change while your page was open, you see “Your details were opened or updated by your employer while this page was open. Reload to see the latest before saving.” Neither message loses anything you typed — reload, check the values, and save again. Every change you make here is recorded, so your employer can see exactly what was updated and when.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('Why TD1 amounts matter') }}">
            {{ __('The claim amounts decide how much income tax is withheld from each pay: a higher total claim means less tax deducted. When your circumstances change — a new dependant, tuition, a second job — complete a new TD1 and enter the new totals here rather than waiting for a year-end surprise.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Related reports ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('T4 slips (Payroll → T4 slips) — the year-end slips employees download from the portal once you Finalize the year.') }}</li>
            <li>{{ __('RL-1 slips (Reports → Employees & Payroll) — the Quebec equivalent, published to the portal the same way.') }}</li>
            <li>{{ __('Payroll register — every posted pay run by employee; each employee’s Pay statements list is their own slice of it.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
