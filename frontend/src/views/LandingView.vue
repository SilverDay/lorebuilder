<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'

const router = useRouter()
const auth   = useAuthStore()

onMounted(async () => {
  // If session is already active, skip the landing page
  if (auth.user === null && !auth.loading) {
    await auth.fetchMe()
  }
  if (auth.isAuthenticated) {
    router.replace('/worlds')
  }
})

const FEATURES = [
  {
    icon: '◈',
    title: 'Entity Management',
    desc: 'Track characters, locations, factions, races, creatures, artefacts, and more — each with custom attributes, tags, and lore notes.',
  },
  {
    icon: '⬡',
    title: 'Relationship Graph',
    desc: 'Visualise how every person, place, and faction connects. Interactive force graph with typed, directional relationships.',
  },
  {
    icon: '✦',
    title: 'AI Narrative Assistant',
    desc: 'Ask AI questions about your world, generate entity backstories, synthesise arc summaries, and spot consistency gaps. Supports Claude, ChatGPT, and Gemini.',
  },
  {
    icon: '◷',
    title: 'Timelines',
    desc: 'Build era-by-era chronologies. Anchor events to entities and see the shape of your world\'s history at a glance.',
  },
  {
    icon: '◎',
    title: 'Story Arcs',
    desc: 'Plan narrative arcs from seed to resolution. Kanban-style board with entity cast, loglines, and progress tracking.',
  },
  {
    icon: '⊞',
    title: 'Research References',
    desc: 'Attach URLs, books, films, and articles as research sources. Link them directly to the entities they inspired.',
  },
  {
    icon: '◉',
    title: 'Open Points',
    desc: 'Never lose a loose thread. Capture unresolved questions, plot holes, and things to clarify — with priority triage.',
  },
  {
    icon: '☰',
    title: 'Multi-User Worlds',
    desc: 'Invite co-authors with scoped roles: owner, admin, author, reviewer, or viewer. Full audit trail of every change.',
  },
]
</script>

<template>
  <div class="landing">

    <!-- ── Nav ── -->
    <nav class="landing-nav">
      <span class="landing-nav__brand">LoreBuilder</span>
      <div class="landing-nav__links">
        <RouterLink to="/tutorial" class="btn btn-ghost btn-sm">User Guide</RouterLink>
        <RouterLink to="/login" class="btn btn-ghost btn-sm">Sign in</RouterLink>
        <RouterLink to="/register" class="btn btn-primary btn-sm">Get started free</RouterLink>
      </div>
    </nav>

    <!-- ── Hero ── -->
    <section class="landing-hero">
      <div class="landing-hero__eyebrow">World-building · AI-assisted · Self-hostable</div>
      <h1 class="landing-hero__title">
        Build richer worlds.<br>
        <span class="landing-hero__accent">Never lose the thread.</span>
      </h1>
      <p class="landing-hero__sub">
        LoreBuilder is a structured knowledge base for fiction writers. Track every character,
        place, faction, and event — then let an AI assistant help you find the gaps.
      </p>
      <div class="landing-hero__cta">
        <RouterLink to="/register" class="btn btn-cta landing-hero__cta-primary">
          Create your world
        </RouterLink>
        <RouterLink to="/login" class="btn btn-ghost landing-hero__cta-secondary">
          Sign in →
        </RouterLink>
      </div>
    </section>

    <!-- ── Features ── -->
    <section class="landing-features">
      <h2 class="landing-section-title">Everything your world needs</h2>
      <div class="landing-features__grid">
        <div v-for="f in FEATURES" :key="f.title" class="feature-card">
          <div class="feature-card__icon">{{ f.icon }}</div>
          <h3 class="feature-card__title">{{ f.title }}</h3>
          <p class="feature-card__desc">{{ f.desc }}</p>
        </div>
      </div>
    </section>

    <!-- ── Bottom CTA ── -->
    <section class="landing-bottom-cta">
      <h2>Ready to start building?</h2>
      <p>Free to self-host. Your data, your world.</p>
      <RouterLink to="/register" class="btn btn-cta">Create an account</RouterLink>
    </section>

    <!-- ── Footer ── -->
    <footer class="landing-footer">
      <span>LoreBuilder · SilverDay Media</span>
      <div class="landing-footer__links">
        <RouterLink to="/tutorial">User Guide</RouterLink>
        <RouterLink to="/login">Sign in</RouterLink>
        <RouterLink to="/register">Register</RouterLink>
      </div>
    </footer>

  </div>
</template>
