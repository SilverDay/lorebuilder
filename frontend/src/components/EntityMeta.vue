<script setup>
/**
 * EntityMeta — type badge, status, attributes table, tags.
 * Receives the full entity object from EntityDetailView.
 */
const props = defineProps({
  entity: { type: Object, required: true },
})

const STATUS_COLOR = {
  draft:     'badge-draft',
  published: 'badge-published',
  archived:  'badge-archived',
}
</script>

<template>
  <section>
    <div class="meta-badges">
      <span class="badge badge-type">{{ entity.type }}</span>
      <span class="badge" :class="STATUS_COLOR[entity.status]">{{ entity.status }}</span>
    </div>

    <p v-if="entity.short_summary" class="entity-summary">{{ entity.short_summary }}</p>

    <template v-if="entity.attributes?.length">
      <h3>Attributes</h3>
      <table class="attr-table">
        <tbody>
          <tr v-for="attr in entity.attributes" :key="attr.attr_key">
            <th>{{ attr.attr_key }}</th>
            <td>{{ attr.attr_value }}</td>
          </tr>
        </tbody>
      </table>
    </template>

    <template v-if="entity.tags?.length">
      <h3>Tags</h3>
      <div class="tag-list">
        <span
          v-for="tag in entity.tags"
          :key="tag.id"
          class="tag"
          :style="{ backgroundColor: tag.color }"
        >{{ tag.name }}</span>
      </div>
    </template>
  </section>
</template>
