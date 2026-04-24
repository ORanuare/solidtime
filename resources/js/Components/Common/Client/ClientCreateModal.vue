<script setup lang="ts">
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import TextareaInput from '@/packages/ui/src/Input/TextareaInput.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { ref } from 'vue';
import type { CreateClientBody } from '@/packages/api/src';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { useFocus } from '@vueuse/core';
import { useClientsStore } from '@/utils/useClients';
import { Field, FieldDescription, FieldLabel, FieldGroup } from '@/packages/ui/src/field';
import { PlusIcon, TrashIcon } from '@heroicons/vue/20/solid';

const { createClient } = useClientsStore();
const show = defineModel('show', { default: false });
const saving = ref(false);

const name = ref('');
const description = ref('');

const contactRows = ref<{ label: string; value: string }[]>([]);

function addContactRow() {
    contactRows.value.push({ label: '', value: '' });
}

function removeContactRow(index: number) {
    contactRows.value.splice(index, 1);
}

async function submit() {
    const contacts = contactRows.value
        .filter((r) => r.label.trim() !== '' && r.value.trim() !== '')
        .map((r) => ({ label: r.label.trim(), value: r.value.trim() }));
    const body = {
        name: name.value,
        description: description.value.trim() ? description.value : null,
        contacts: contacts.length > 0 ? contacts : null,
    } as CreateClientBody;
    await createClient(body);
    name.value = '';
    description.value = '';
    contactRows.value = [];
    show.value = false;
}

const clientNameInput = ref<HTMLInputElement | null>(null);
useFocus(clientNameInput, { initialValue: true });
</script>

<template>
    <DialogModal closeable :show="show" max-width="3xl" @close="show = false">
        <template #title>
            <div class="flex space-x-2">
                <span> Create Client </span>
            </div>
        </template>

        <template #content>
            <FieldGroup>
                <Field class="w-full">
                    <FieldLabel for="clientName">Client Name</FieldLabel>
                    <TextInput
                        id="clientName"
                        ref="clientNameInput"
                        v-model="name"
                        type="text"
                        placeholder="Client Name"
                        class="block w-full"
                        required
                        autocomplete="clientName"
                        @keydown.enter="submit" />
                </Field>
                <Field class="w-full">
                    <FieldLabel for="clientDescription">Description</FieldLabel>
                    <TextareaInput
                        id="clientDescription"
                        v-model="description"
                        :rows="4"
                        placeholder="Type, services, social handles, notes…"
                        class="block w-full" />
                </Field>
                <Field class="w-full min-w-0">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <FieldLabel class="mb-0">Contacts</FieldLabel>
                        <SecondaryButton type="button" :icon="PlusIcon" @click="addContactRow">
                            Add contact
                        </SecondaryButton>
                    </div>
                    <FieldDescription class="text-text-tertiary text-xs mt-1.5 mb-3">
                        Add ways to reach this client, e.g. email, phone, or social.
                    </FieldDescription>
                    <div class="space-y-3 w-full min-w-0">
                        <div
                            v-for="(row, index) in contactRows"
                            :key="index"
                            class="grid grid-cols-1 min-[480px]:grid-cols-[1fr_1fr_auto] gap-2 w-full min-w-0 items-end">
                            <TextInput
                                :id="'contact-label-' + index"
                                v-model="row.label"
                                type="text"
                                class="min-w-0 w-full"
                                placeholder="Label"
                                :aria-label="'Contact label ' + (index + 1)" />
                            <TextInput
                                :id="'contact-value-' + index"
                                v-model="row.value"
                                type="text"
                                class="min-w-0 w-full"
                                placeholder="Value"
                                :aria-label="'Contact value ' + (index + 1)" />
                            <button
                                type="button"
                                class="p-2 rounded-md text-text-tertiary hover:text-text-primary hover:bg-secondary shrink-0 justify-self-end min-[480px]:justify-self-center"
                                :aria-label="'Remove contact row ' + (index + 1)"
                                @click="removeContactRow(index)">
                                <TrashIcon class="w-5 h-5" />
                            </button>
                        </div>
                    </div>
                </Field>
            </FieldGroup>
        </template>
        <template #footer>
            <SecondaryButton @click="show = false"> Cancel </SecondaryButton>

            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': saving }"
                :disabled="saving"
                @click="submit">
                Create Client
            </PrimaryButton>
        </template>
    </DialogModal>
</template>

<style scoped></style>
