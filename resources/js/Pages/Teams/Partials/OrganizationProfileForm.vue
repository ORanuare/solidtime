<script setup lang="ts">
import { ref } from 'vue';
import type { InertiaForm } from '@inertiajs/vue3';
import FormSection from '@/Components/FormSection.vue';
import ActionMessage from '@/Components/ActionMessage.vue';
import { Field, FieldLabel, FieldError } from '@/packages/ui/src/field';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import type { Organization } from '@/types/models';
import type { Permissions } from '@/types/jetstream';

type TeamUpdateForm = InertiaForm<{
    _method: 'PUT';
    name: string;
    currency: string;
    photo: File | null;
}>;

const props = defineProps<{
    team: Organization;
    permissions: Permissions;
    form: TeamUpdateForm;
    managesProfilePhotos: boolean;
}>();

const emit = defineEmits<{
    submitted: [];
    'remove-photo': [];
}>();

const photoPreview = ref<ArrayBuffer | string | null | undefined>(null);
const photoInput = ref<HTMLInputElement | null>(null);

const selectNewPhoto = () => {
    photoInput.value?.click();
};

const updatePhotoPreview = () => {
    const photo = photoInput.value?.files?.[0];
    if (!photo) return;

    const reader = new FileReader();
    reader.onload = (e) => {
        photoPreview.value = e.target?.result;
    };
    reader.readAsDataURL(photo);
};

function preparePhotoForSubmit(): void {
    if (photoInput.value?.files?.length) {
        props.form.photo = photoInput.value.files[0] ?? null;
    }
}

function clearPhotoUiAfterSave(): void {
    photoPreview.value = null;
    if (photoInput.value?.value) {
        photoInput.value.value = '';
    }
}

defineExpose({
    preparePhotoForSubmit,
    clearPhotoUiAfterSave,
});
</script>

<template>
    <FormSection @submitted="emit('submitted')">
        <template #title>Organization profile</template>

        <template #description>
            Update how your organization appears in the app, including its name and photo.
        </template>

        <template #form>
            <div
                v-if="managesProfilePhotos"
                class="col-span-6 sm:col-span-4">
                <input
                    id="team-photo"
                    ref="photoInput"
                    type="file"
                    class="hidden"
                    @change="updatePhotoPreview" />

                <FieldLabel for="team-photo">Photo</FieldLabel>

                <div v-show="!photoPreview" class="mt-2">
                    <img
                        :src="team.profile_photo_url"
                        :alt="team.name"
                        class="rounded-full h-20 w-20 object-cover" />
                </div>

                <div v-show="photoPreview" class="mt-2">
                    <span
                        class="block rounded-full w-20 h-20 bg-cover bg-no-repeat bg-center"
                        :style="'background-image: url(\'' + photoPreview + '\');'" />
                </div>

                <SecondaryButton
                    v-if="permissions.canUpdateTeam"
                    class="mt-2 me-2"
                    type="button"
                    @click.prevent="selectNewPhoto">
                    Select a new photo
                </SecondaryButton>

                <SecondaryButton
                    v-if="permissions.canUpdateTeam && team.profile_photo_path"
                    type="button"
                    class="mt-2"
                    @click.prevent="emit('remove-photo')">
                    Remove photo
                </SecondaryButton>

                <FieldError v-if="form.errors.photo">{{ form.errors.photo }}</FieldError>
            </div>

            <Field class="col-span-6 sm:col-span-4">
                <FieldLabel for="org-profile-name">Organization name</FieldLabel>

                <TextInput
                    id="org-profile-name"
                    v-model="form.name"
                    type="text"
                    class="block w-full"
                    :disabled="!permissions.canUpdateTeam" />

                <FieldError v-if="form.errors.name">{{ form.errors.name }}</FieldError>
            </Field>
        </template>

        <template v-if="permissions.canUpdateTeam" #actions>
            <ActionMessage :on="form.recentlySuccessful" class="me-3"> Saved. </ActionMessage>

            <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                Save
            </PrimaryButton>
        </template>
    </FormSection>
</template>
