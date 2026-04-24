<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import FormSection from '@/Components/FormSection.vue';
import { FieldLabel } from '@/packages/ui/src/field';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import type { Organization } from '@/types/models';
import { CreditCardIcon } from '@heroicons/vue/20/solid';
import { isBillingActivated } from '@/utils/billing';
import { canManageBilling } from '@/utils/permissions';

defineProps<{
    team: Organization;
}>();
</script>

<template>
    <FormSection>
        <template #title>Organization owner</template>

        <template #description>
            The person who owns this organization. Billing is managed separately.
        </template>

        <template #form>
            <div class="col-span-6 flex items-center justify-between">
                <div>
                    <FieldLabel>Owner</FieldLabel>

                    <div class="flex items-center mt-2">
                        <img
                            class="w-12 h-12 rounded-full object-cover"
                            :src="team.owner.profile_photo_url"
                            :alt="team.owner.name" />

                        <div class="ms-4 leading-tight">
                            <div class="text-text-primary">
                                {{ team.owner.name }}
                            </div>
                            <div class="text-text-secondary text-sm">
                                {{ team.owner.email }}
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <Link v-if="isBillingActivated() && canManageBilling()" href="/billing">
                        <PrimaryButton :icon="CreditCardIcon" type="button">
                            Go to Billing
                        </PrimaryButton>
                    </Link>
                </div>
            </div>
        </template>
    </FormSection>
</template>
