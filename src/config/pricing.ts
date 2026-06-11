/**
 * Pricing Configuration for WP AI Mind
 * 
 * Updated based on Unit-Economics Audit (June 2026) - Issue #746
 * Adjusted Pro Managed tier to ensure sustainability at 0.5% conversion rate.
 */

export interface PricingTier {
  id: string;
  name: string;
  monthlyPrice: number;
  yearlyPrice: number;
  creditAllowance: number;
  features: string[];
}

export const PRICING_TIERS: Record<string, PricingTier> = {
  free: {
    id: 'free',
    name: 'Free',
    monthlyPrice: 0,
    yearlyPrice: 0,
    creditAllowance: 1000, // 1k credits
    features: ['Basic AI Chat', 'Limited History', 'Community Support'],
  },
  pro_managed: {
    id: 'pro_managed',
    name: 'Pro Managed',
    // Updated from $29/mo ($348/yr) to ~$79/yr
    monthlyPrice: 6.58, // Equivalent monthly for $79/yr
    yearlyPrice: 79,
    // Updated allowance to 1M credits
    creditAllowance: 1000000, 
    features: [
      'Unlimited AI Chat',
      '1M Monthly Credits',
      'Priority Support',
      'Advanced Context Window',
      'Managed Inference Optimization'
    ],
  },
  enterprise: {
    id: 'enterprise',
    name: 'Enterprise',
    monthlyPrice: 199,
    yearlyPrice: 1990,
    creditAllowance: 10000000, // 10M credits
    features: [
      'Dedicated Inference Cluster',
      'Custom Model Fine-tuning',
      'SLA Guarantee',
      '24/7 Phone Support',
      'Unlimited Credits'
    ],
  },
};

/**
 * Helper to get tier by ID
 */
export function getPricingTier(tierId: string): PricingTier | undefined {
  return PRICING_TIERS[tierId];
}

/**
 * Helper to calculate effective monthly cost based on billing cycle
 */
export function getEffectiveMonthlyPrice(tierId: string, billingCycle: 'monthly' | 'yearly'): number {
  const tier = getPricingTier(tierId);
  if (!tier) return 0;
  
  if (billingCycle === 'yearly') {
    return tier.yearlyPrice / 12;
  }
  return tier.monthlyPrice;
}