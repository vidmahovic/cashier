<?php

namespace Laravel\Cashier;

use Carbon\Carbon;
use LogicException;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Stripe\Plan;

class Subscription extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'trial_ends_at', 'ends_at',
        'created_at', 'updated_at',
    ];

    /**
     * Indicates if the plan change should be prorated.
     *
     * @var bool
     */
    protected $prorate = true;

    /**
     * The date on which the billing cycle should be anchored.
     *
     * @var string|null
     */
    protected $billingCycleAnchor = null;

    protected $coupon = null;

    public $model_namespace = 'App';

    /**
     * Get the user that owns the subscription.
     */
    public function user()
    {
        $model = getenv('STRIPE_MODEL') ?: config('services.stripe.model', 'User');

        return $this->belongsTo($model, 'user_id');
    }

    /**
     * Determine if the subscription is active, on trial, or within its grace period.
     *
     * @return bool
     */
    public function valid()
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function active()
    {
        return is_null($this->ends_at) || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is no longer active.
     *
     * @return bool
     */
    public function cancelled()
    {
        return ! is_null($this->ends_at);
    }

    /**
     * Determine if the subscription is within its trial period.
     *
     * @return bool
     */
    public function onTrial()
    {
        if (! is_null($this->trial_ends_at)) {
            return Carbon::today()->lt($this->trial_ends_at);
        } else {
            return false;
        }
    }

    /**
     * Determine if the subscription is within its grace period after cancellation.
     *
     * @return bool
     */
    public function onGracePeriod()
    {
        if (! is_null($endsAt = $this->ends_at)) {
            return Carbon::now()->lt(Carbon::instance($endsAt));
        } else {
            return false;
        }
    }

    /**
     * Increment the quantity of the subscription.
     *
     * @param  int  $count
     * @return $this
     */
    public function incrementQuantity($count = 1)
    {
        $this->updateQuantity($this->quantity + $count);

        return $this;
    }

    /**
     *  Increment the quantity of the subscription, and invoice immediately.
     *
     * @param  int  $count
     * @return $this
     */
    public function incrementAndInvoice($count = 1)
    {
        $this->incrementQuantity($count);

        $this->user->invoice();

        return $this;
    }

    /**
     * Decrement the quantity of the subscription.
     *
     * @param  int  $count
     * @return $this
     */
    public function decrementQuantity($count = 1)
    {
        $this->updateQuantity(max(1, $this->quantity - $count));

        return $this;
    }

    /**
     * Update the quantity of the subscription.
     *
     * @param  int  $quantity
     * @param  \Stripe\Customer|null  $customer
     * @return $this
     */
    public function updateQuantity($quantity, $customer = null)
    {
        $subscription = $this->asStripeSubscription();

        $subscription->quantity = $quantity;

        $subscription->save();

        $this->quantity = $quantity;

        $this->save();

        return $this;
    }

    /**
     * Indicate that the plan change should not be prorated.
     *
     * @return $this
     */
    public function noProrate()
    {
        $this->prorate = false;

        return $this;
    }

    /**
     * Change the billing cycle anchor on a plan change.
     *
     * @param  int|string  $date
     * @return $this
     */
    public function anchorBillingCycleOn($date = 'now')
    {
        if ($date instanceof DateTimeInterface) {
            $date = $date->getTimestamp();
        }

        $this->billingCycleAnchor = $date;

        return $this;
    }

    public function withCoupon($coupon)
    {
        $this->coupon = $coupon;
        return $this;
    }

    /**
     * Swap the subscription to a new Stripe plan.
     *
     * @param  string  $plan
     * @return $this
     */
    public function swap($plan)
    {
        $subscription = $this->asStripeSubscription();

        $subscription->plan = $plan;

        $subscription->prorate = $this->prorate;

        if(!is_null($this->coupon)) $subscription->coupon = $this->coupon;

        if (! is_null($this->billingCycleAnchor)) {
            $subscription->billingCycleAnchor = $this->billingCycleAnchor;
        }

        // If no specific trial end date has been set, the default behavior should be
        // to maintain the current trial state, whether that is "active" or to run
        // the swap out with the exact number of days left on this current plan.
        if ($this->onTrial()) {
            $subscription->trial_end = $this->trial_ends_at->getTimestamp();
        } else {
            $subscription->trial_end = 'now';
        }

        // Again, if no explicit quantity was set, the default behaviors should be to
        // maintain the current quantity onto the new plan. This is a sensible one
        // that should be the expected behavior for most developers with Stripe.
        if ($this->quantity) {
            $subscription->quantity = $this->quantity;
        }

        $subscription->save();

        $this->user->invoice();

        $this->fill([
            'stripe_plan' => $plan,
            'ends_at' => null,
        ])->save();

        return $this;
    }

    /**
     * Cancel the subscription at the end of the billing period.
     *
     * @return $this
     */
    public function cancel()
    {
        $subscription = $this->asStripeSubscription();

        $subscription->cancel(['at_period_end' => true]);

        // If the user was on trial, we will set the grace period to end when the trial
        // would have ended. Otherwise, we'll retrieve the end of the billing period
        // period and make that the end of the grace period for this current user.
        if ($this->onTrial()) {
            $this->ends_at = $this->trial_ends_at;
        } else {
            $this->ends_at = Carbon::createFromTimestamp(
                $subscription->current_period_end
            );
        }

        $this->save();

        return $this;
    }

    /**
     * Cancel the subscription immediately.
     *
     * @return $this
     */
    public function cancelNow()
    {
        $subscription = $this->asStripeSubscription();

        $subscription->cancel();

        $this->markAsCancelled();

        return $this;
    }

    /**
     * Mark the subscription as cancelled.
     *
     * @return void
     */
    public function markAsCancelled()
    {
        $this->fill(['ends_at' => Carbon::now()])->save();
    }

    /**
     * Resume the cancelled subscription.
     *
     * @return $this
     *
     * @throws \LogicException
     */
    public function resume()
    {
        if (! $this->onGracePeriod()) {
            throw new \LogicException("Unable to resume subscription that is not within grace period.");
        }

        $subscription = $this->asStripeSubscription();

        // To resume the subscription we need to set the plan parameter on the Stripe
        // subscription object. This will force Stripe to resume this subscription
        // where we left off. Then, we'll set the proper trial ending timestamp.
        $subscription->plan = $this->stripe_plan;

        $subscription->prorate = $this->prorate;

        if ($this->onTrial()) {
            $subscription->trial_end = $this->trial_ends_at->getTimestamp();
        } else {
            $subscription->trial_end = 'now';
        }

        $subscription->save();

        // Finally, we will remove the ending timestamp from the user's record in the
        // local database to indicate that the subscription is active again and is
        // no longer "cancelled". Then we will save this record in the database.
        $this->fill(['ends_at' => null])->save();

        return $this;
    }

    /**
     * Get the subscription as a Stripe subscription object.
     *
     * @return \Stripe\Subscription
     */
    public function asStripeSubscription()
    {
        return $this->user->asStripeCustomer()->subscriptions->retrieve($this->stripe_id);
    }

    public function getPaidAtAttribute()
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', Cache::get('sub-payment-'.$this->id, function() {

            $remote_subscription = $this->asStripeSubscription();

            if($remote_subscription->plan->interval === 'year')
                $current_period_start = $this->user->resolveActiveBillingCycleStart();
            else
                $current_period_start = Carbon::createFromTimestamp((int) $remote_subscription->current_period_start);

            Cache::put('sub-payment-'.$this->id, $current_period_start, Carbon::now()->addMonth());

            return $current_period_start;
        }));
    }

    public function getActiveStripePlanAttribute()
    {
        if(is_null($this->old_stripe_plan)) return $this->stripe_plan;
        return $this->old_stripe_plan;
    }

    /**
     * Return the datetime of the next billing cycle. Billing cycle starts when customer pays for his new billing cycle
     * (i.e. pays for the next subscription period). Billing cycle can last longer than refresh cycle if
     * Stripe subscription's billing cycle is more than 1 month long. It can also last shorter
     * if a user is on trial period which lasts less than 1 month.
     *
     * @return Carbon
     */
    public function getNextBillingCycleAttribute()
    {
        if($this->valid()) {

            if(! Cache::has('sub-next-billing-'.$this->id)) {
                $current_period_end = Carbon::createFromTimestamp($this->asStripeSubscription()->current_period_end);
                Cache::forever('sub-next-billing-'.$this->id, $current_period_end->addSecond());
            }

            return Cache::get('sub-next-billing-'.$this->id);

        }

        return null;
    }

    /**
     * Return the datetime of a next refresh cycle. Refresh cycle is when customer's units get refreshed (emails_spent
     * is set to 0). If customer's subscription is monthly subscription, then next_refresh_cycle is the same
     * as next_billing_cycle.
     *
     * @return Carbon
     */
    public function getNextRefreshCycleAttribute()
    {
        if($this->valid()) {
            $next_refresh_cycle = $this->paid_at->addMonth();
            return $next_refresh_cycle->lt($this->next_billing_cycle) ? $next_refresh_cycle : $this->next_billing_cycle;
        }

        return null;
    }

    // todo: this is email remaining total! not only for current subscription!
    public function getEmailsRemainingAttribute()
    {
        $remaining = $this->emails_available - $this->emails_spent;
        return $remaining > 0 ? $remaining : 0;
    }

    public function getCurrentEmailsRemainingAttribute()
    {
        $remaining = $this->emails_available - $this->user->additional_units_bought - $this->emails_spent;
        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Get emails spent between the beginning and the end of a subscription. Note that new subscription is created
     * only if active one is cancelled (changing plans, cancelling and then resuming, etc. does
     * not cause creation of a new subscription).
     *
     * @return int
     */
    public function getTotalEmailsSpentAttribute()
    {
        $stats_model = $this->model_namespace.'\Stats';
        $stats = $stats_model::select(
            \DB::raw('sum(emails_sent) as emails_sent')
        )
            ->userCredential($this->user)
            ->between($this->created_at, $this->ends_at)
            ->value('emails_sent');
        try {
            return intval($stats);
        } catch(\Exception $e) {
            return 0;
        }
    }

    /**
     * Get emails spent in current billing cycle which starts when Stripe customer paid his last invoice.
     *
     * @return int
     */
    public function getEmailsSpentAttribute()
    {
        $stats_model = $this->model_namespace.'\Stats';
        try {
            return intval(
                $stats_model::select(
                    \DB::raw('sum(emails_sent) as emails_sent')
                )
                    ->userCredential($this->user)
                    ->between($this->paid_at, Carbon::now())
                    ->value('emails_sent')
            );
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getEmailsAvailableAttribute()
    {
        $plan_model = $this->model_namespace.'\Plan';
        $active_plan = $plan_model::findByStripePlan($this->active_stripe_plan);
        return $active_plan->number_of_emails + $this->user->additional_units_bought;
    }

}
