<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import SectionBorder from '@/Components/SectionBorder.vue';
import OrganizationOwnerForm from '@/Pages/Teams/Partials/OrganizationOwnerForm.vue';
import OrganizationProfileForm from '@/Pages/Teams/Partials/OrganizationProfileForm.vue';
import type { Organization } from '@/types/models';
import type { Permissions } from '@/types/jetstream';

const props = defineProps<{
    team: Organization;
    permissions: Permissions;
}>();

const page = usePage<{
    jetstream: {
        managesProfilePhotos: boolean;
    };
}>();

const form = useForm({
    _method: 'PUT' as const,
    name: props.team.name,
    currency: props.team.currency,
    photo: null as File | null,
});

watch(
    () => [props.team.name, props.team.currency] as const,
    ([name, currency]) => {
        form.name = name;
        form.currency = currency;
    }
);

const profileFormRef = ref<InstanceType<typeof OrganizationProfileForm> | null>(null);

const submitTeamUpdate = () => {
    profileFormRef.value?.preparePhotoForSubmit();

    form.post(route('teams.update', props.team.id), {
        errorBag: 'updateTeamName',
        preserveScroll: true,
        onSuccess: () => {
            profileFormRef.value?.clearPhotoUiAfterSave();
        },
    });
};

const deletePhoto = () => {
    router.delete(route('teams.profile-photo.destroy', props.team.id), {
        preserveScroll: true,
        onSuccess: () => {
            profileFormRef.value?.clearPhotoUiAfterSave();
        },
    });
};
</script>

<template>
    <OrganizationProfileForm
        ref="profileFormRef"
        :team="team"
        :permissions="permissions"
        :form="form"
        :manages-profile-photos="page.props.jetstream.managesProfilePhotos"
        @submitted="submitTeamUpdate"
        @remove-photo="deletePhoto" />

    <SectionBorder />

    <OrganizationOwnerForm :team="team" />
</template>
