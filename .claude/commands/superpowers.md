---
description: Superpowers 7-Phase Software Development Methodology
---
# Superpowers Workflow

This workflow orchestrates the comprehensive 7-phase software development methodology provided by the `obra/superpowers` framework, ensuring strict TDD, YAGNI, and DRY principles.

When instructed to use `/superpowers`, execute the following steps strictly in order, utilizing the corresponding skills from `.agents/skills/` for each step:

1. **Brainstorming (`brainstorming` skill)**
   - Do not write code yet. Ask questions to refine the rough ideas, explore alternatives, and present the design in chunks for validation. Save the design document.

2. **Setup Workspace (`using-git-worktrees` skill)**
   - After design approval, create an isolated workspace on a new branch. Run project setup and verify a clean test baseline.

3. **Planning (`writing-plans` skill)**
   - Break work down into bite-sized tasks (2-5 minutes each). Ensure every task has exact file paths, complete code, and verification steps.

4. **Execution (`subagent-driven-development` or `executing-plans` skill)**
   - Dispatch to subagents for execution using a two-stage review (spec compliance, code quality), or execute in batches with human checkpoints.

5. **Implementation via TDD (`test-driven-development` skill)**
   - Strictly follow RED-GREEN-REFACTOR. Write a failing test, watch it fail, write minimal code to pass it, watch it pass, then commit. 

6. **Code Review (`requesting-code-review` skill)**
   - Between tasks, perform a rigorous review against the plan. Report issues by severity; critical issues block progress.

7. **Completion (`finishing-a-development-branch` skill)**
   - When all tasks are complete, verify test success. Present options to the user (merge/PR/keep/discard) and clean up the worktree.

**Note:** Always consult the individual `SKILL.md` files in `.agents/skills/<skill-name>/` for deep context and rules on each phase.
