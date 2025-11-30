<script setup lang="ts">
import PageHeader from '../components/ui/PageHeader.vue';
import Section from '../components/ui/Section.vue';
import Heading from '../components/ui/Heading.vue';

</script>

<template>
        <PageHeader title="Headings" subtitle="Typography scale and color usage" />

        <div class="mt-8 space-y-12">
          <Section title="Scale">
            <div class="space-y-3">
              <Heading as="h1" size="5xl" weight="bold" color="smart-blue">Heading 1 / 5xl</Heading>
              <Heading as="h2" size="4xl" weight="bold" color="smart-blue">Heading 2 / 4xl</Heading>
              <Heading as="h3" size="3xl" weight="semibold" color="smart-blue">Heading 3 / 3xl</Heading>
              <Heading as="h4" size="2xl" weight="semibold">Heading 4 / 2xl</Heading>
              <Heading as="h5" size="xl" weight="semibold">Heading 5 / xl</Heading>
              <Heading as="h6" size="lg" weight="medium">Heading 6 / lg</Heading>
            </div>
          </Section>

          <Section title="Color Variants">
            <div class="space-y-3">
              <Heading as="h3" size="2xl" weight="semibold" color="smart-blue">Smart Blue</Heading>
              <Heading as="h3" size="2xl" weight="semibold" color="regal-navy">Regal Navy</Heading>
              <Heading as="h3" size="2xl" weight="semibold" color="sapphire">Sapphire</Heading>
              <Heading as="h3" size="2xl" weight="semibold">Default (Smart Blue 100)</Heading>
            </div>
          </Section>
        </div>
</template>


