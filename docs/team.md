---
layout: page

title: Team & credits
---

<script setup>
  import { VPTeamPage, VPTeamPageTitle, VPTeamPageSection, VPTeamMembers } from "vitepress/theme";
  import { projectManagers, teamMembers } from "./_data/team";
</script>

<VPTeamPage>
  <VPTeamPageTitle>
    <template #title>Team & credits</template>
    <template #lead>
      ArcPanel is developed in the open on
      <a href="https://github.com/arc-panel-oss/arcpanel">GitHub</a>.
      It is based on HestiaCP 1.10.5, which is itself based on VestaCP. Most of the ArcPanel code
      was written by the HestiaCP and VestaCP projects and their contributors. We thank them.
    </template>
  </VPTeamPageTitle>
  <div class="credits-note">
    <p>
      The people below maintain HestiaCP, the upstream project. They are credited here for their
      work and are not ArcPanel maintainers. Please send questions and bug reports about ArcPanel to the
      <a href="https://github.com/arc-panel-oss/arcpanel/issues">ArcPanel issue tracker</a>,
      not to them. The full list of upstream contributors is on the
      <a href="https://github.com/hestiacp/hestiacp/graphs/contributors">HestiaCP contributors page</a>.
    </p>
  </div>
  <VPTeamPageSection>
    <template #title>HestiaCP project managers (upstream)</template>
    <template #members>
      <VPTeamMembers :members="projectManagers" />
    </template>
  </VPTeamPageSection>
  <VPTeamPageSection>
    <template #title>HestiaCP team members (upstream)</template>
    <template #members>
      <VPTeamMembers :members="teamMembers" />
    </template>
  </VPTeamPageSection>
</VPTeamPage>

<style>
.credits-note {
  max-width: 768px;
  margin: 0 auto;
  padding: 0 24px;
  line-height: 1.6;
  color: var(--vp-c-text-2);
}
.credits-note a,
.VPTeamPageTitle a {
  color: var(--vp-c-brand-1);
  text-decoration: underline;
}
</style>
